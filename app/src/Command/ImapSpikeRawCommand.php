<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MailAccountRepository;
use App\Service\ImapConnectionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * Etap 3.0 (spike): jak pobrać bajtowo-wierny, re-parsowalny `.eml` w sposób read-only.
 *
 * Porównuje dwa warianty pozyskania pełnego źródła RFC822 dla kilku najnowszych wiadomości:
 *   A) rekonstrukcja z webklex: `Header::$raw` + "\r\n\r\n" + `Message::getRawBody()`,
 *   B) surowy `UID FETCH <uid> BODY.PEEK[]` prosto z protokołu (kompletne, niezmienione źródło).
 *
 * Dla każdego wariantu liczy bajty/sha256, sprawdza strukturę (nagłówki + pusta linia) i
 * re-parsuje przez `Message::fromString()`, porównując Message-ID / temat / liczbę załączników
 * z oryginałem. Wynik rozstrzyga, którego wariantu użyć w 3.1–3.3 i nad czym liczyć `sha256`.
 *
 * READ-ONLY: wariant B używa `BODY.PEEK[]` (nie ustawia `\Seen`); zapytanie listy woła
 * `leaveUnread()`. Nic nie kasuje, nie zapisuje encji ani plików.
 */
#[AsCommand(
    name: 'app:imap:spike-raw',
    description: 'Spike etapu 3.0: porównuje pobranie bajtowo-wiernego .eml (rekonstrukcja vs BODY.PEEK[]).',
)]
class ImapSpikeRawCommand extends Command {
    public function __construct(
        private readonly MailAccountRepository $mailAccountRepository,
        private readonly ImapConnectionFactory $connectionFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'ID konta MailAccount')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ile najnowszych wiadomości porównać', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $accountId = (string) $input->getOption('account');
        if (!ctype_digit($accountId)) {
            $io->error('Podaj --account=<ID> (liczba całkowita).');

            return Command::FAILURE;
        }

        $account = $this->mailAccountRepository->find((int) $accountId);
        if ($account === null) {
            $io->error(sprintf('Nie znaleziono konta o ID %s.', $accountId));

            return Command::FAILURE;
        }

        $limit = max(1, (int) $input->getOption('limit'));

        try {
            $client = $this->connectionFactory->connect($account);
        } catch (\Throwable $e) {
            $io->error(sprintf('Połączenie nie powiodło się: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        try {
            $folder = $client->getFolder($account->getFolder());
            if ($folder === null) {
                $io->error(sprintf('Folder "%s" nie istnieje.', $account->getFolder()));

                return Command::FAILURE;
            }

            $messages = $folder->query()
                ->whereAll()
                ->leaveUnread()
                ->setFetchBody(true)
                ->setFetchOrderDesc()
                ->limit($limit)
                ->get();

            if ($messages->count() === 0) {
                $io->warning('Brak wiadomości do porównania.');

                return Command::SUCCESS;
            }

            $connection = $client->getConnection();

            foreach ($messages as $message) {
                $uid = (int) $message->getUid();
                $io->section(sprintf('UID %d — %s', $uid, $this->truncate((string) $message->getSubject(), 60)));

                $origMessageId = (string) $message->getMessageId();
                $origSubject = (string) $message->getSubject();
                $origAttachments = $message->getAttachments()->count();

                // Wariant A — rekonstrukcja z tego, co webklex już sparsował.
                $emlA = rtrim($message->getHeader()->raw, "\r\n") . "\r\n\r\n" . $message->getRawBody();

                // Wariant B — surowe, kompletne źródło prosto z serwera (PEEK = bez \Seen).
                // Wiele itemów ('UID' + 'BODY.PEEK[]') wymusza w webklex gałąź budującą tablicę
                // asocjacyjną kluczowaną nazwą z odpowiedzi serwera; serwer zwraca PEEK jako 'BODY[]'.
                $emlB = null;
                try {
                    $fetched = $connection->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
                    $emlB = $fetched[$uid]['BODY[]'] ?? null;
                    if ($emlB === null && isset($fetched[$uid]) && is_array($fetched[$uid])) {
                        $io->comment('Diagnostyka B — klucze odpowiedzi: ' . implode(', ', array_keys($fetched[$uid])));
                    }
                } catch (\Throwable $e) {
                    $io->comment('Wariant B — błąd fetch: ' . $e->getMessage());
                }

                $a = $this->evaluate($emlA, $origMessageId, $origSubject, $origAttachments);
                $b = $this->evaluate($emlB, $origMessageId, $origSubject, $origAttachments);

                $labels = [
                    'bajty', 'sha256 (12)', 'zaczyna się nagłówkiem', 'ma pustą linię (CRLFCRLF)',
                    're-parse', 'Message-ID zgodny', 'temat zgodny', '#załączników zgodne',
                ];
                $rows = [];
                foreach ($labels as $i => $label) {
                    $rows[] = [$label, (string) $a[$i], (string) $b[$i]];
                }
                $io->table(['Metryka', 'A: rekonstrukcja', 'B: BODY.PEEK[]'], $rows);

                $io->writeln(sprintf(
                    'A === B (bajtowo identyczne): <info>%s</info>',
                    ($emlB !== null && $emlA === $emlB) ? 'TAK' : 'nie',
                ));
                $io->writeln(sprintf(
                    'Oryginał: Message-ID=%s, #załączników=%d',
                    $origMessageId !== '' ? $origMessageId : '(brak)',
                    $origAttachments,
                ));
            }

            $io->success('Spike zakończony. Read-only: wariant B nie ustawia \\Seen (PEEK).');

            return Command::SUCCESS;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Liczy metryki danego wariantu .eml i re-parsuje go, porównując z oryginałem.
     *
     * @return array{0:mixed,1:mixed,2:string,3:string,4:string,5:string,6:string,7:string}
     */
    private function evaluate(?string $eml, string $origMessageId, string $origSubject, int $origAttachments): array {
        if ($eml === null || $eml === '') {
            return ['—', '—', '—', '—', '—', '—', '—', '—'];
        }

        $startsHeader = preg_match('/^[\x21-\x39\x3b-\x7e]+:/', $eml) === 1 ? 'tak' : 'NIE';
        $hasSeparator = str_contains($eml, "\r\n\r\n") ? 'tak' : 'NIE';

        try {
            $reparsed = Message::fromString($eml);
            $reparse = 'OK';
            $midMatch = ((string) $reparsed->getMessageId() === $origMessageId) ? 'tak' : 'NIE';
            $subjMatch = ((string) $reparsed->getSubject() === $origSubject) ? 'tak' : 'NIE';
            $attMatch = ($reparsed->getAttachments()->count() === $origAttachments) ? 'tak' : 'NIE';
        } catch (\Throwable $e) {
            $reparse = 'BŁĄD: ' . $this->truncate($e->getMessage(), 30);
            $midMatch = $subjMatch = $attMatch = '—';
        }

        return [
            strlen($eml),
            substr(hash('sha256', $eml), 0, 12),
            $startsHeader,
            $hasSeparator,
            $reparse,
            $midMatch,
            $subjMatch,
            $attMatch,
        ];
    }

    private function truncate(string $value, int $length): string {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1) . '…';
    }
}
