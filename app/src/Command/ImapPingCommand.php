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

/**
 * Etap 2 (spike): "wbicie gwoździa" w warstwę IMAP.
 *
 * Łączy się z serwerem konta `MailAccount` (poświadczenia deszyfrowane z bazy),
 * listuje foldery i pokazuje nagłówki kilku najnowszych wiadomości ze skonfigurowanego
 * folderu. READ-ONLY: nie pobiera treści, nie zmienia flag (`leaveUnread`), nic nie kasuje.
 * Żadnego zapisu `.eml` ani encji `Message` — to dopiero etap 3.
 */
#[AsCommand(
    name: 'app:imap:ping',
    description: 'Spike etapu 2: łączy się z IMAP konta i listuje foldery + nagłówki najnowszych wiadomości (read-only).',
)]
class ImapPingCommand extends Command {
    public function __construct(
        private readonly MailAccountRepository $mailAccountRepository,
        private readonly ImapConnectionFactory $connectionFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'ID konta MailAccount')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ile najnowszych nagłówków pokazać', '5');
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

        $io->section(sprintf('Konto #%d — %s', $account->getId(), $account->getLabel()));
        $io->definitionList(
            ['Host' => sprintf('%s:%d', $account->getHost(), $account->getPort())],
            ['Login' => $account->getImapLogin()],
            ['Folder' => $account->getFolder()],
            ['Auth' => $account->getAuthType()->label()],
        );

        try {
            $client = $this->connectionFactory->connect($account);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error(sprintf('Połączenie/logowanie nie powiodło się: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        try {
            $folders = $client->getFolders();
            $io->section(sprintf('Foldery (%d)', $folders->count()));
            $io->listing(array_map(
                static fn ($folder): string => $folder->full_name,
                $folders->all(),
            ));

            $folder = $client->getFolder($account->getFolder());
            if ($folder === null) {
                $io->warning(sprintf('Folder "%s" nie istnieje na serwerze.', $account->getFolder()));

                return Command::SUCCESS;
            }

            $status = $folder->examine();
            $io->section(sprintf(
                '"%s" — wiadomości: %s',
                $account->getFolder(),
                $status['exists'] ?? '?',
            ));

            $messages = $folder->query()
                ->whereAll()
                ->leaveUnread()
                ->setFetchBody(false)
                ->setFetchOrderDesc()
                ->limit($limit)
                ->get();

            if ($messages->count() === 0) {
                $io->text('Brak wiadomości do pokazania.');

                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($messages as $message) {
                $from = $message->getFrom()->first();
                $rows[] = [
                    (string) $message->getDate(),
                    $from?->full ?: '—',
                    $this->truncate((string) $message->getSubject(), 50),
                    $this->truncate((string) $message->getMessageId(), 40),
                    (int) $message->getSize(),
                ];
            }

            $io->table(['Data', 'Od', 'Temat', 'Message-ID', 'Rozmiar (B)'], $rows);
            $io->success('Połączenie IMAP działa.');

            return Command::SUCCESS;
        } finally {
            $client->disconnect();
        }
    }

    private function truncate(string $value, int $length): string {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1) . '…';
    }
}
