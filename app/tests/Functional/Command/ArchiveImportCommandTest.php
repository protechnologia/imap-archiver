<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\ArchiveImportCommand;
use App\Entity\MailAccount;
use App\Model\ImportSummary;
use App\Repository\MailAccountRepository;
use App\Service\ImportManager;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:archive:import` — warstwa wiersza poleceń nad `ImportManagerem` (etap 3.3).
 *
 * Sam import ma własny test (`ImportManagerTest`), więc tutaj interesuje nas WYŁĄCZNIE to, co
 * robi komenda: walidacja opcji, przekazanie ich dalej i przełożenie wyniku na kod wyjścia.
 * `ImportManager` jest podstawiony — inaczej test próbowałby połączyć się z serwerem IMAP.
 *
 * Dwa przypadki niosą tu realne ryzyko: `testFlagaDryRunJestPrzekazywanaDalej()` (odwrócona flaga
 * oznaczałaby, że „próbny" przebieg zapisuje do archiwum i bazy, a ekran dalej mówi „dry-run")
 * oraz `testPrzebiegZBledamiKonczySieKodemBledu()` (od kodu wyjścia zależy zachowanie crona i CI).
 */
class ArchiveImportCommandTest extends KernelTestCase {
    private EntityManagerInterface $em;
    private MailAccountRepository $accounts;

    protected function setUp(): void {
        self::bootKernel();

        $this->em       = self::getContainer()->get(EntityManagerInterface::class);
        $this->accounts = self::getContainer()->get(MailAccountRepository::class);
    }

    /**
     * Flaga `--dry-run` musi dojechać do `ImportManagera` — to jedyne miejsce, które decyduje,
     * czy przebieg cokolwiek zapisze. Tu wyjątkowo używamy atrapy z OCZEKIWANIEM (`expects`),
     * bo przedmiotem testu jest właśnie wywołanie z konkretnymi argumentami.
     */
    public function testFlagaDryRunJestPrzekazywanaDalej(): void {
        $account = $this->givenAccount();

        $manager = $this->createMock(ImportManager::class);
        $manager->expects($this->once())
            ->method('import')
            ->with($account, 2026, true)
            ->willReturn($this->summary());

        $status = $this->runCommand($manager, ['--account' => (string) $account->getId(), '--year' => '2026', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $status);
    }

    public function testBezFlagiDryRunPrzebiegJestNormalny(): void {
        $account = $this->givenAccount();

        $manager = $this->createMock(ImportManager::class);
        $manager->expects($this->once())
            ->method('import')
            ->with($account, 2025, false)
            ->willReturn($this->summary());

        $this->runCommand($manager, ['--account' => (string) $account->getId(), '--year' => '2025']);
    }

    /**
     * Przebieg, w którym choć jeden mail się nie udał, kończy się `FAILURE` — mimo że reszta
     * została zaimportowana. Cron ma o tym wiedzieć, a błędy mają być widoczne w wyjściu.
     */
    public function testPrzebiegZBledamiKonczySieKodemBledu(): void {
        $account = $this->givenAccount();
        $manager = $this->managerReturning($this->summary(errors: ['UID 7: serwer nie zwrócił źródła (BODY[])']));

        $tester = $this->tester($manager);
        $status = $tester->execute(['--account' => (string) $account->getId(), '--year' => '2026']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('UID 7', $tester->getDisplay());
    }

    public function testCzystyPrzebiegKonczySieSukcesem(): void {
        $account = $this->givenAccount();
        $manager = $this->managerReturning($this->summary(imported: 3, verified: 3));

        $tester = $this->tester($manager);
        $status = $tester->execute(['--account' => (string) $account->getId(), '--year' => '2026']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('import zakończony', $tester->getDisplay());
    }

    /**
     * Wyjątek z importu (np. brak folderu na serwerze albo nieobsługiwany typ uwierzytelniania)
     * ma dać czytelny komunikat i `FAILURE`, a nie stack trace.
     */
    public function testWyjatekZImportuDajeKodBledu(): void {
        $account = $this->givenAccount();

        $manager = $this->createMock(ImportManager::class);
        $manager->expects($this->once())
            ->method('import')
            ->willThrowException(new \RuntimeException('Folder "INBOX" nie istnieje na serwerze.'));

        $tester = $this->tester($manager);
        $status = $tester->execute(['--account' => (string) $account->getId(), '--year' => '2026']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('nie istnieje na serwerze', $tester->getDisplay());
    }

    /**
     * Wejście odrzucane ZANIM cokolwiek dotknie sieci — atrapa ma nie zostać w ogóle zawołana.
     *
     * @param array<string, string> $options Opcje komendy, np. ["--year" => "2026"]
     */
    #[DataProvider('bledneWejscie')]
    public function testBledneWejscieNieUruchamiaImportu(array $options): void {
        $this->givenAccount();

        $manager = $this->createMock(ImportManager::class);
        $manager->expects($this->never())->method('import');

        $this->assertSame(Command::FAILURE, $this->runCommand($manager, $options));
    }

    /**
     * @return iterable<string, array{array<string, string>}> Zestawy opcji, które mają zostać odrzucone
     */
    public static function bledneWejscie(): iterable {
        yield 'brak --account'          => [['--year' => '2026']];
        yield 'nieliczbowe --account'   => [['--account' => 'abc', '--year' => '2026']];
        yield 'nieznane konto'          => [['--account' => '999999', '--year' => '2026']];
        yield 'brak --year'             => [['--account' => '1']];
        yield 'rok nie czterocyfrowy'   => [['--account' => '1', '--year' => '26']];
        yield 'rok poza zakresem'       => [['--account' => '1', '--year' => '1900']];
    }

    /**
     * Uruchamia komendę z podstawionym managerem i zwraca kod wyjścia.
     *
     * @param ImportManager         $manager Atrapa managera
     * @param array<string, string> $options Opcje komendy
     *
     * @return int Kod wyjścia (`Command::SUCCESS` / `Command::FAILURE`)
     */
    private function runCommand(ImportManager $manager, array $options): int {
        return $this->tester($manager)->execute($options);
    }

    /**
     * Buduje testera komendy z prawdziwym repozytorium kont i podstawionym managerem.
     *
     * Komendę składamy ręcznie zamiast wyciągać z kontenera: podmiana usługi w kontenerze
     * testowym działałaby tylko przed pierwszym leniwym załadowaniem komendy, a tu i tak nie
     * sprawdzamy okablowania (od tego jest `lint:container`), lecz zachowanie samej klasy.
     *
     * @param ImportManager $manager Atrapa managera
     *
     * @return CommandTester Gotowy tester
     */
    private function tester(ImportManager $manager): CommandTester {
        return new CommandTester(new ArchiveImportCommand($this->accounts, $manager));
    }

    /**
     * Atrapa managera oddająca gotowe podsumowanie.
     *
     * @param ImportSummary $summary Podsumowanie do zwrócenia
     *
     * @return ImportManager Atrapa
     */
    private function managerReturning(ImportSummary $summary): ImportManager {
        $manager = $this->createStub(ImportManager::class);
        $manager->method('import')->willReturn($summary);

        return $manager;
    }

    /**
     * Podsumowanie importu do podstawienia w atrapie.
     *
     * @param int          $imported Ile zaimportowano, np. 3
     * @param int          $verified Ile zweryfikowano, np. 3
     * @param list<string> $errors   Błędy przebiegu, np. ["UID 7: …"]
     *
     * @return ImportSummary Podsumowanie
     */
    private function summary(int $imported = 0, int $verified = 0, array $errors = []): ImportSummary {
        return new ImportSummary(
            year:       2026,
            candidates: $imported + count($errors),
            imported:   $imported,
            skipped:    0,
            verified:   $verified,
            errors:     $errors,
            dryRun:     false,
        );
    }

    /**
     * Zapisuje konto, na którym komenda ma pracować.
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(): MailAccount {
        $account = EntityFactory::account();
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
