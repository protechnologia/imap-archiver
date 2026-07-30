<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MailAccount;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:mail:list` — diagnostyka listy wiadomości (etap 4.1).
 *
 * Samo zapytanie (sortowanie, paginacja, filtr, eskejpowanie wieloznaczników) jest już w całości
 * pokryte przez `MessageRepositoryTest`. Tutaj sprawdzamy WYŁĄCZNIE kontrakt komendy: walidację
 * wejścia i kody wyjścia — z jednym niebanalnym przypadkiem, `testZeroTrafienKonczySieSukcesem()`.
 * Pusty wynik to poprawna odpowiedź, nie błąd; gdyby komenda zwracała wtedy `FAILURE`, każde
 * wywołanie z crona na pustym roczniku wyglądałoby jak awaria.
 */
class MailListCommandTest extends KernelTestCase {
    private CommandTester $command;
    private EntityManagerInterface $em;

    protected function setUp(): void {
        self::bootKernel();

        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:mail:list'));
    }

    /**
     * Bez `--account` repozytorium dostałoby pustą listę kont i oddało pustkę — komenda ma jednak
     * odróżnić „nie podałeś konta" od „na tym koncie nic nie ma".
     *
     * @param array<string, string> $options Opcje komendy, np. ["--account" => "abc"]
     */
    #[DataProvider('bledneWejscie')]
    public function testBledneWejscieKonczySieBledem(array $options): void {
        $this->assertSame(Command::FAILURE, $this->command->execute($options));
    }

    /**
     * @return iterable<string, array{array<string, string>}> Zestawy opcji, które mają zostać odrzucone
     */
    public static function bledneWejscie(): iterable {
        yield 'brak --account'        => [[]];
        yield 'nieliczbowe --account' => [['--account' => 'abc']];
    }

    public function testZeroTrafienKonczySieSukcesem(): void {
        $account = $this->givenAccount();

        $status = $this->command->execute(['--account' => (string) $account->getId()]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Brak wiadomości', $this->command->getDisplay());
    }

    /**
     * Smoke: wiadomość z konta pojawia się w tabeli, a cudza nie — zawężenie po kontach działa
     * tak samo z wiersza poleceń, jak na liście w przeglądarce.
     */
    public function testWypisujeWiadomosciPodanegoKonta(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $this->givenMessage($moje, 'Faktura VAT 12/2026');
        $this->givenMessage($obce, 'Cudza wiadomość');

        $status = $this->command->execute(['--account' => (string) $moje->getId()]);
        $wyjscie = $this->command->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Faktura VAT 12/2026', $wyjscie);
        $this->assertStringNotContainsString('Cudza wiadomość', $wyjscie);
    }

    /**
     * Wiadomość bez `Date` jest wypisywana jawnie jako „— (brak Date)", a nie pustą komórką —
     * takie maile lądują na końcu listy i chcemy widzieć, że to nie błąd sortowania.
     */
    public function testWiadomoscBezDatyJestOznaczonaWTabeli(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Bez daty', null);

        $this->command->execute(['--account' => (string) $account->getId()]);

        $this->assertStringContainsString('brak Date', $this->command->getDisplay());
    }

    /**
     * Zapisuje konto.
     *
     * @param string $label Etykieta konta, np. "Moje konto"
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(string $label = 'Konto testowe'): MailAccount {
        $account = EntityFactory::account($label);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Zapisuje wiadomość na koncie.
     *
     * @param MailAccount             $account Konto źródłowe
     * @param string                  $subject Temat, np. "Faktura VAT 12/2026"
     * @param \DateTimeImmutable|null $date    Nagłówek `Date`; null = wiadomość bez daty
     */
    private function givenMessage(MailAccount $account, string $subject, ?\DateTimeImmutable $date = new \DateTimeImmutable('2026-06-01 08:00')): void {
        $this->em->persist(EntityFactory::message($account, $subject, $date));
        $this->em->flush();
    }
}
