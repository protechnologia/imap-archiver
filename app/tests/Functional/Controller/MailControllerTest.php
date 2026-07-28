<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Support\Fixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Podgląd poczty przez pełny stos HTTP (etap 4.2).
 *
 * Testy jednostkowe Votera sprawdzają samą REGUŁĘ; tu sprawdzamy rzeczy, których w izolacji
 * zobaczyć się nie da, bo nie mieszkają w klasie kontrolera: `#[IsGranted]` i `#[CurrentUser]`
 * rozwiązywane przez framework, faktyczne wywołanie Votera przy detalu, wymaganie `\d+` z routingu
 * i to, że szablony w ogóle się renderują (błąd w Twigu to 500, nie czerwony unit test).
 *
 * Najważniejszy przypadek: `testNieprzypisanyAdminDostaje403NaCudzymMailu()` — dopóki jest zielony,
 * `ROLE_ADMIN` nie jest cichym skrótem do cudzej korespondencji.
 *
 * Logujemy przez `loginUser()`, a nie formularzem: przedmiotem testu jest autoryzacja, nie
 * uwierzytelnianie (formularz i stateless CSRF mają własne zachowanie, opisane w CLAUDE.md).
 */
class MailControllerTest extends WebTestCase {
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void {
        $this->client = self::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testNiezalogowanyJestPrzekierowanyDoLogowania(): void {
        $this->client->request('GET', '/mail');

        $this->assertResponseRedirects('http://localhost/login');
    }

    /**
     * Lista pokazuje wiadomości z kont użytkownika i NIE pokazuje cudzych — zawężenie po M2M
     * działa w zapytaniu, zanim cokolwiek trafi do szablonu.
     */
    public function testPrzypisanyUzytkownikWidziSwojeWiadomosciAleNieCudze(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $user = $this->givenUser('user@example.com', $moje);
        $this->givenMessage($moje, 'Moja wiadomość');
        $this->givenMessage($obce, 'Cudza wiadomość');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Moja wiadomość');
        $this->assertSelectorTextNotContains('body', 'Cudza wiadomość');
    }

    public function testPrzypisanyUzytkownikOtwieraDetalSwojejWiadomosci(): void {
        $account = $this->givenAccount();
        $user    = $this->givenUser('user@example.com', $account);
        $message = $this->givenMessage($account, 'Faktura VAT 12/2026');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Faktura VAT 12/2026');
    }

    public function testNieprzypisanyUzytkownikDostaje403NaCudzymMailu(): void {
        $account = $this->givenAccount();
        $message = $this->givenMessage($account, 'Nie dla ciebie');
        $obcy    = $this->givenUser('outsider@example.com');

        $this->client->loginUser($obcy);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Decyzja z etapu 4.2: na froncie admin jest zwykłym czytelnikiem swojej poczty. Rola
     * administracyjna NIE otwiera cudzej skrzynki — od tego jest przypisanie konta w panelu.
     */
    public function testNieprzypisanyAdminDostaje403NaCudzymMailu(): void {
        $account = $this->givenAccount();
        $message = $this->givenMessage($account, 'Nie dla admina');
        $admin   = $this->givenUser('admin@example.com', null, ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPrzypisanyAdminWidziWiadomosc(): void {
        $account = $this->givenAccount();
        $message = $this->givenMessage($account, 'Poczta admina');
        $admin   = $this->givenUser('admin@example.com', $account, ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
    }

    /**
     * Użytkownik bez przypisanego konta widzi pustą listę z komunikatem — to poprawny stan,
     * a nie błąd; lista nie ma prawa wtedy pokazać czyichkolwiek wiadomości.
     */
    public function testUzytkownikBezKontWidziPustaListeZKomunikatem(): void {
        $obce = $this->givenAccount('Obce konto');
        $this->givenMessage($obce, 'Cudza wiadomość');
        $obcy = $this->givenUser('outsider@example.com');

        $this->client->loginUser($obcy);
        $this->client->request('GET', '/mail');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Nie masz przypisanych kont pocztowych');
        $this->assertSelectorTextNotContains('body', 'Cudza wiadomość');
    }

    public function testNieistniejaceIdDaje404(): void {
        $user = $this->givenUser('user@example.com');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * `Requirement::DIGITS` w routingu — adres nie-liczbowy nie jest tożsamością wiadomości,
     * więc nie ma prawa dojść do kontrolera.
     */
    public function testNieliczbowyIdWOgoleNiePasujeDoTrasy(): void {
        $user = $this->givenUser('user@example.com');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/abc');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Numer strony to stan widoku, nie tożsamość — wartość poza zakresem ma zostać przycięta,
     * a nie wywrócić żądania.
     */
    public function testNumerStronyPozaZakresemNieWywracaListy(): void {
        $account = $this->givenAccount();
        $user    = $this->givenUser('user@example.com', $account);
        $this->givenMessage($account, 'Jedyna');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail?page=999');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Jedyna');
    }

    /**
     * Zapisuje konto IMAP.
     *
     * @param string $label Etykieta konta, np. "Moje konto"
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(string $label = 'Konto testowe'): MailAccount {
        $account = Fixtures::account($label);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Zapisuje użytkownika, opcjonalnie z dostępem do konta.
     *
     * @param string           $email   Adres e-mail, np. "user@example.com"
     * @param MailAccount|null $account Konto do przypisania albo null (użytkownik bez dostępu)
     * @param list<string>     $roles   Role bez `ROLE_USER`, np. ["ROLE_ADMIN"]
     *
     * @return User Użytkownik z nadanym ID
     */
    private function givenUser(string $email, ?MailAccount $account = null, array $roles = []): User {
        $user = Fixtures::user($email, $roles);
        $account?->addUser($user);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Zapisuje wiadomość na koncie.
     *
     * @param MailAccount $account Konto źródłowe
     * @param string      $subject Temat, np. "Faktura VAT 12/2026"
     *
     * @return Message Wiadomość z nadanym ID
     */
    private function givenMessage(MailAccount $account, string $subject): Message {
        $message = Fixtures::message($account, $subject, new \DateTimeImmutable('2026-06-01 08:00'));
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }
}
