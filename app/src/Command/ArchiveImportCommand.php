<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MailAccount;
use App\Model\ImportSummary;
use App\Repository\MailAccountRepository;
use App\Service\ImportManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Etap 3.3: import wiadomości z konkretnego roku do archiwum (`.eml` + indeks `Message`).
 *
 * PODETAP 3.3b: komenda waliduje opcje, pobiera `.eml` z danego roku i zapisuje do archiwum
 * (przez `ImportManager`), po czym pokazuje podsumowanie (znalezione / pobrane / błędy).
 * Read-only wobec skrzynki. Indeks `Message`/`Attachment` i weryfikacja (`verified`) — 3.3c.
 *
 * Przykładowy przebieg (`app:archive:import --account=12 --year=2026`):
 *
 *   Import: konto #12 — poczta@example.com, rok 2026
 *   ------------------------------------------------
 *
 *    -------- ----------------------
 *     Host     imap.example.com:993
 *     Login    poczta@example.com
 *     Folder   INBOX
 *    -------- ----------------------
 *
 *    ---------------------------------------------------- --------
 *     Metryka                                              Liczba
 *    ---------------------------------------------------- --------
 *     Znalezione w roku (wg daty przyjęcia przez serwer)   3
 *     Pobrane i zapisane                                   3
 *     Błędy                                                0
 *    ---------------------------------------------------- --------
 *
 *    [OK] Konto "poczta@example.com", rok 2026 — import zakończony.
 *
 *    ! [NOTE] Indeks w bazie (Message/Attachment) i weryfikacja (verified) dochodzą w 3.3c.
 */
#[AsCommand(
    name: 'app:archive:import',
    description: 'Etap 3.3: import wiadomości z roku do archiwum (.eml + indeks). 3.3b: pobranie + zapis.',
)]
class ArchiveImportCommand extends Command {

    /** 
     * __construct
     */
    public function __construct(
        private readonly MailAccountRepository $mailAccountRepository,
        private readonly ImportManager $importManager,
    ) {
        parent::__construct();
    }

    /**
     * configure
     */
    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'ID konta MailAccount')
            ->addOption('year',    null, InputOption::VALUE_REQUIRED, 'Rok do zaimportowania, np. 2025')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Przebieg próbny (bez zapisu do archiwum i DB)');
    }

    /**
     * execute
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $account = $this->resolveAccount($io, $input);
        if ($account === null) {
            return Command::FAILURE;
        }

        $year = $this->resolveYear($io, $input);
        if ($year === null) {
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $this->renderHeader($io, $account, $year, $dryRun);

        $summary = $this->runImport($io, $account, $year, $dryRun);
        if ($summary === null) {
            return Command::FAILURE;
        }

        $this->renderSummary($io, $account, $summary);

        return $summary->errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Waliduje `--account` i zwraca konto; null (z komunikatem błędu) gdy brak/niepoprawne/nieznane.
     */
    private function resolveAccount(SymfonyStyle $io, InputInterface $input): ?MailAccount {
        $accountId = (string) $input->getOption('account');
        if (!ctype_digit($accountId)) {
            $io->error('Podaj --account=<ID> (liczba całkowita).');

            return null;
        }

        $account = $this->mailAccountRepository->find((int) $accountId);
        if ($account === null) {
            $io->error(sprintf('Nie znaleziono konta o ID %s.', $accountId));

            return null;
        }

        return $account;
    }

    /**
     * Waliduje `--year` (czterocyfrowy, w sensownym zakresie) i zwraca go; null (z komunikatem) gdy zły.
     */
    private function resolveYear(SymfonyStyle $io, InputInterface $input): ?int {
        $yearRaw = (string) $input->getOption('year');
        if (!ctype_digit($yearRaw) || strlen($yearRaw) !== 4) {
            $io->error('Podaj --year=<RRRR> (czterocyfrowy rok, np. 2025).');

            return null;
        }

        $year = (int) $yearRaw;
        $maxYear = (int) date('Y') + 1;
        if ($year < 1970 || $year > $maxYear) {
            $io->error(sprintf('Rok %d poza sensownym zakresem (1970–%d).', $year, $maxYear));

            return null;
        }

        return $year;
    }

    /**
     * Wypisuje nagłówek przebiegu: co i na jakim koncie importujemy.
     */
    private function renderHeader(SymfonyStyle $io, MailAccount $account, int $year, bool $dryRun): void {
        $io->section(sprintf('Import: konto #%d — %s, rok %d%s', $account->getId(), $account->getLabel(), $year, $dryRun ? ' (dry-run)' : ''));
        $io->definitionList(
            ['Host' => sprintf('%s:%d', $account->getHost(), $account->getPort())],
            ['Login' => $account->getImapLogin()],
            ['Folder' => $account->getFolder()],
        );
    }

    /**
     * Uruchamia import i zwraca podsumowanie; null (z komunikatem błędu) gdy przebieg się nie powiódł.
     */
    private function runImport(SymfonyStyle $io, MailAccount $account, int $year, bool $dryRun): ?ImportSummary {
        try {
            return $this->importManager->import($account, $year, $dryRun);
        }
        catch (\InvalidArgumentException $e) {
            // np. konto z authType innym niż Password (ImapConnectionFactory).
            $io->error($e->getMessage());

            return null;
        }
        catch (\Throwable $e) {
            $io->error(sprintf('Import nie powiódł się: %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * Wypisuje podsumowanie: tabela metryk, ewentualna lista błędów, status i notka o 3.3c.
     */
    private function renderSummary(SymfonyStyle $io, MailAccount $account, ImportSummary $summary): void {
        $io->table(['Metryka', 'Liczba'], [
            ['Znalezione w roku (wg daty przyjęcia przez serwer)', $summary->candidates],
            [$summary->dryRun ? 'Pobrane (dry-run, bez zapisu)' : 'Pobrane i zapisane', $summary->fetched],
            ['Błędy', count($summary->errors)],
        ]);

        if ($summary->errors !== []) {
            $io->section('Błędy');
            $io->listing($summary->errors);
        }

        $io->success(sprintf('Konto "%s", rok %d — import zakończony%s.', $account->getLabel(), $summary->year, $summary->dryRun ? ' (dry-run)' : ''));
        $io->note('Indeks w bazie (Message/Attachment) i weryfikacja (verified) dochodzą w 3.3c.');
    }
}
