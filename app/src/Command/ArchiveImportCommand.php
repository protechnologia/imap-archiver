<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MailAccountRepository;
use App\Service\ImapImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Etap 3.3: import wiadomości z konkretnego roku do archiwum (`.eml` + indeks `Message`).
 *
 * PODETAP 3.3a: komenda + SELEKCJA. Waliduje opcje, łączy się z kontem i pokazuje, ile maili
 * jest w danym roku (po INTERNALDATE). Read-only — nic jeszcze nie pobiera ani nie zapisuje;
 * pobranie `.eml` i indeks dochodzą w 3.3b/3.3c.
 */
#[AsCommand(
    name: 'app:archive:import',
    description: 'Etap 3.3: import wiadomości z roku do archiwum (.eml + indeks). 3.3a: selekcja (read-only).',
)]
class ArchiveImportCommand extends Command {

    public function __construct(
        private readonly MailAccountRepository $mailAccountRepository,
        private readonly ImapImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'ID konta MailAccount')
            ->addOption('year',    null, InputOption::VALUE_REQUIRED, 'Rok do zaimportowania, np. 2025')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Przebieg próbny (bez zapisu do archiwum i DB)');
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

        $yearRaw = (string) $input->getOption('year');
        if (!ctype_digit($yearRaw) || \strlen($yearRaw) !== 4) {
            $io->error('Podaj --year=<RRRR> (czterocyfrowy rok, np. 2025).');

            return Command::FAILURE;
        }
        $year = (int) $yearRaw;
        $maxYear = (int) date('Y') + 1;
        if ($year < 1970 || $year > $maxYear) {
            $io->error(sprintf('Rok %d poza sensownym zakresem (1970–%d).', $year, $maxYear));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $io->section(sprintf('Import: konto #%d — %s, rok %d%s', $account->getId(), $account->getLabel(), $year, $dryRun ? ' (dry-run)' : ''));
        $io->definitionList(
            ['Host' => sprintf('%s:%d', $account->getHost(), $account->getPort())],
            ['Login' => $account->getImapLogin()],
            ['Folder' => $account->getFolder()],
        );

        try {
            $summary = $this->importer->import($account, $year, $dryRun);
        }
        catch (\InvalidArgumentException $e) {
            // np. konto z authType innym niż Password (ImapConnectionFactory).
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        catch (\Throwable $e) {
            $io->error(sprintf('Import nie powiódł się: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Konto "%s", rok %d: znaleziono %d wiadomości (wg daty przyjęcia przez serwer).',
            $account->getLabel(),
            $summary->year,
            $summary->candidates,
        ));
        $io->note('Etap 3.3a: to dopiero SELEKCJA — pobranie .eml i zapis do archiwum dochodzą w 3.3b/3.3c.');

        return Command::SUCCESS;
    }
}
