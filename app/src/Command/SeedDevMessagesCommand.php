<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MailAccountRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Zaślepki wiadomości do OGLĄDANIA LISTY w dev (etap 4.4).
 *
 * Trzy maile z realnego importu nie wystarczą, żeby zobaczyć paginację, przewijanie kolumny ani
 * zachowanie szukania — a import z prawdziwej skrzynki dla samego wyglądu byłby przesadą.
 *
 * Wpisy powstają WYŁĄCZNIE w indeksie, bez plików `.eml` w archiwum, więc mają `verified = false`.
 * To nie jest szczegół: bezpieczne usuwanie z etapu 6 kasuje z serwera IMAP tylko wiadomości
 * `verified`, a te nie mają pokrycia w archiwum i nigdy nie mogą się do tego zakwalifikować.
 *
 * Komenda odmawia pracy poza `dev` — w `prod` zaśmiecałaby indeks realnego konta.
 */
#[AsCommand(
    name: 'app:dev:seed-messages',
    description: 'Generuje zaślepki wiadomości w indeksie (tylko dev, bez plików .eml)',
)]
class SeedDevMessagesCommand extends Command {

    /** Wzorce tematów — `%d` dostaje numer, żeby dało się szukać konkretnej wiadomości. */
    private const SUBJECTS = [
        'Faktura VAT %d/2026',
        'Potwierdzenie zamówienia #%d',
        'Newsletter — wydanie %d',
        'Przypomnienie o płatności (%d)',
        'Raport miesięczny nr %d',
        'Zapytanie ofertowe %d',
        'Umowa do podpisu — wersja %d',
        'Podsumowanie tygodnia %d',
        'Zmiana terminu spotkania (%d)',
        'Dostawa zrealizowana — paczka %d',
    ];

    private const SENDERS = [
        ['Anna Kowalska', 'anna.kowalska@example.com'],
        ['Jan Nowak', 'jan.nowak@firma.example'],
        ['Biuro Obsługi Klienta', 'bok@sklep.example'],
        ['Zespół Księgowości', 'ksiegowosc@example.org'],
        ['Michał Wiśniewski', 'm.wisniewski@example.net'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly MailAccountRepository $mailAccountRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'ID konta; domyślnie pierwsze w bazie')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Ile wiadomości wygenerować', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment !== 'dev') {
            $io->error(sprintf('Komenda działa tylko w środowisku dev (jest: %s).', $this->environment));

            return Command::FAILURE;
        }

        $accountId = $this->resolveAccountId($input->getOption('account'));
        if ($accountId === null) {
            $io->error('Nie ma żadnego konta pocztowego — najpierw załóż je w panelu.');

            return Command::FAILURE;
        }

        $count    = max(1, (int) $input->getOption('count'));
        $inserted = 0;

        for ($i = 1; $i <= $count; ++$i) {
            $inserted += $this->insertMessage($accountId, $i);
        }

        $io->success(sprintf('Wstawiono %d z %d wiadomości do konta %d (reszta już istniała).', $inserted, $count, $accountId));

        return Command::SUCCESS;
    }

    /**
     * Wstawia pojedynczą zaślepkę; duplikaty po `sha256` pomija.
     *
     * @param int $accountId ID konta, np. 67
     * @param int $number    Numer w serii, np. 42 — wchodzi w temat i różnicuje dane
     *
     * @return int 1 gdy wstawiono, 0 gdy taka wiadomość już była
     */
    private function insertMessage(int $accountId, int $number): int {
        $sha256                  = hash('sha256', sprintf('dev-seed-%d-%d', $accountId, $number));
        [$fromName, $fromEmail]  = self::SENDERS[$number % count(self::SENDERS)];

        // Co dwunasta bez daty — sprawdza sortowanie NULLS LAST z etapu 4.1.
        $sentAt = $number % 12 === 0
            ? null
            : (new \DateTimeImmutable('2026-06-16 10:00'))->modify(sprintf('-%d hours -%d minutes', $number * 7, $number * 13));

        return (int) $this->connection->executeStatement(
            'INSERT INTO message (account_id, folder, message_id, subject, from_name, from_email, sent_at,
                                  size, sha256, has_attachments, verified, body, imap_uid, archive_path)
             VALUES (:account, :folder, :messageId, :subject, :fromName, :fromEmail, :sentAt, :size, :sha256,
                     :hasAttachments, false, :body, :imapUid, :archivePath)
             ON CONFLICT (account_id, sha256) DO NOTHING',
            [
                'account'        => $accountId,
                'folder'         => 'INBOX',
                'messageId'      => sprintf('<dev-seed-%d@example.com>', $number),
                'subject'        => sprintf(self::SUBJECTS[$number % count(self::SUBJECTS)], $number),
                'fromName'       => $fromName,
                'fromEmail'      => $fromEmail,
                'sentAt'         => $sentAt?->format('Y-m-d H:i:s'),
                'size'           => 1024 * (3 + ($number % 90)),
                'sha256'         => $sha256,
                'hasAttachments' => $number % 5 === 0 ? 'true' : 'false',
                'body'           => sprintf('Treść testowa wiadomości numer %d. Wygenerowana lokalnie do oglądania listy.', $number),
                'imapUid'        => 10000 + $number,
                'archivePath'    => sprintf('%d/2026/06/%s.eml', $accountId, $sha256),
            ],
        );
    }

    /**
     * Konto z opcji albo pierwsze z bazy.
     *
     * @param mixed $option Wartość `--account` albo null
     *
     * @return int|null ID konta, np. 67; null gdy w bazie nie ma żadnego
     */
    private function resolveAccountId(mixed $option): ?int {
        if ($option !== null) {
            return (int) $option;
        }

        $accountId = $this->connection->fetchOne('SELECT id FROM mail_account ORDER BY id LIMIT 1');

        return $accountId === false ? null : (int) $accountId;
    }
}
