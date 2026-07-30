<?php

declare(strict_types=1);

namespace App\Tests\Integration\Doctrine;

use App\Entity\MailAccount;
use App\Repository\MailAccountRepository;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Typ Doctrine `encrypted_string` w działaniu (etap 1.4).
 *
 * `CredentialEncryptorTest` sprawdza sam algorytm; tutaj chodzi o rzecz, której w izolacji nie
 * widać: czy szyfrowanie jest FAKTYCZNIE WPIĘTE w zapis encji. Dlatego asercje idą na SUROWYM
 * SQL-u, z pominięciem ORM-a — pytanie brzmi „co naprawdę leży w kolumnie", a nie „co ORM mi odda".
 *
 * Test pilnuje też okablowania z `Kernel::boot()`: typy Doctrine to globalne singletony bez DI,
 * więc encryptor jest w nie wstrzykiwany ręcznie. Gdyby ktoś przeniósł rejestrację do
 * `doctrine.yaml` „dla porządku" (gotcha z CLAUDE.md), instancja typu zostałaby nadpisana przy
 * budowie połączenia, encryptor by zniknął i zapis konta poleciałby `LogicException`.
 */
class EncryptedStringTypeTest extends KernelTestCase {
    private const SECRET = 'Tajne123!';

    private EntityManagerInterface $em;
    private Connection $connection;
    private MailAccountRepository $accounts;

    protected function setUp(): void {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->accounts   = self::getContainer()->get(MailAccountRepository::class);
    }

    /**
     * Najważniejsza asercja całego pliku: w kolumnie NIE MA jawnego hasła. Sprawdzane surowym
     * SQL-em, bo przez ORM ta różnica jest z definicji niewidoczna.
     */
    public function testWKolumnieNieMaPlaintextu(): void {
        $account = $this->givenAccountWithSecret(self::SECRET);

        $wKolumnie = $this->rawSecretOf($account);

        $this->assertNotSame(self::SECRET, $wKolumnie);
        $this->assertStringNotContainsString(self::SECRET, $wKolumnie);
        $this->assertNotFalse(base64_decode($wKolumnie, true), 'Szyfrogram trzymamy jako base64');
    }

    /**
     * Druga strona kontraktu: aplikacja ma dostać jawną wartość bez żadnego jawnego odszyfrowania
     * w kodzie. Czyścimy identity map, żeby wartość naprawdę przeszła przez hydratację z bazy,
     * a nie wróciła z pamięci Doctrine'a.
     */
    public function testOdczytPrzezOrmOddajeJawnaWartosc(): void {
        $id = (int) $this->givenAccountWithSecret(self::SECRET)->getId();

        $this->em->clear();
        $zBazy = $this->accounts->find($id);

        $this->assertInstanceOf(MailAccount::class, $zBazy);
        $this->assertSame(self::SECRET, $zBazy->getSecret());
    }

    /**
     * Nonce losowany per zapis działa też przez warstwę Doctrine: dwa konta z tym samym hasłem
     * mają w bazie różne szyfrogramy. Inaczej `SELECT secret, count(*) GROUP BY secret`
     * pokazywałby, które konta współdzielą hasło.
     */
    public function testDwaKontaZTymSamymHaslemMajaRozneSzyfrogramy(): void {
        $pierwsze = $this->givenAccountWithSecret(self::SECRET, 'Konto A');
        $drugie   = $this->givenAccountWithSecret(self::SECRET, 'Konto B');

        $this->assertNotSame($this->rawSecretOf($pierwsze), $this->rawSecretOf($drugie));
    }

    /**
     * `secret` jest nullable (konto może jeszcze nie mieć poświadczenia) — null ma zostać nullem,
     * a nie zaszyfrowanym pustym stringiem, bo inaczej „brak hasła" stałby się nieodróżnialny
     * od „hasło puste".
     */
    public function testNullZostajeNullem(): void {
        $account = $this->givenAccountWithSecret(null);

        $this->assertNull($this->connection->fetchOne(
            'SELECT secret FROM mail_account WHERE id = ?',
            [$account->getId()],
        ));

        $this->em->clear();
        $this->assertNull($this->accounts->find((int) $account->getId())?->getSecret());
    }

    /**
     * Zapisuje konto z podanym poświadczeniem.
     *
     * @param string|null $secret Jawne poświadczenie albo null, np. "Tajne123!"
     * @param string      $label  Etykieta konta, np. "Konto A"
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccountWithSecret(?string $secret, string $label = 'Konto testowe'): MailAccount {
        $account = EntityFactory::account($label)->setSecret($secret);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Czyta kolumnę `secret` z pominięciem ORM-a (bez konwersji typu Doctrine).
     *
     * @param MailAccount $account Zapisane konto
     *
     * @return string Zawartość kolumny — oczekujemy szyfrogramu base64
     */
    private function rawSecretOf(MailAccount $account): string {
        return (string) $this->connection->fetchOne(
            'SELECT secret FROM mail_account WHERE id = ?',
            [$account->getId()],
        );
    }
}
