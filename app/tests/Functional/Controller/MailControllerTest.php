<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Podgląd poczty przez pełny stos HTTP (etapy 4.2 i 4.3c).
 *
 * Testy jednostkowe Votera sprawdzają samą REGUŁĘ; tu sprawdzamy rzeczy, których w izolacji
 * zobaczyć się nie da, bo nie mieszkają w klasie kontrolera: `#[IsGranted]` i `#[CurrentUser]`
 * rozwiązywane przez framework, faktyczne wywołanie Votera przy detalu, wymaganie `\d+` z routingu
 * i to, że szablony w ogóle się renderują (błąd w Twigu to 500, nie czerwony unit test).
 *
 * Najważniejszy przypadek: `testNieprzypisanyAdminDostaje403NaCudzymMailu()` — dopóki jest zielony,
 * `ROLE_ADMIN` nie jest cichym skrótem do cudzej korespondencji.
 *
 * Od 4.3c doszły dwa przypadki pilnujące KONTRAKTU Z TURBO (obecność ramki, kompletność deep linku).
 * `WebTestCase` nie wykonuje JS-u, więc nie sprawdzamy tu ZACHOWANIA Turbo — tylko to, czy serwer
 * wysyła HTML, na którym Turbo może zadziałać. Zaznaczenia wiersza po kliknięciu świadomie nie
 * testujemy: do 4.4 nie przestawia się ono w ogóle (luka opisana w `_list.html.twig`), a potem
 * będzie już sprawą Live Componentu, nie kontrolera.
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
     * `?account=` zawęża listę do jednego konta (etap 4.3a) — panel kont po lewej niczego innego
     * nie robi, tylko podstawia ten parametr.
     */
    public function testWybraneKontoZawezaListe(): void {
        $pierwsze = $this->givenAccount('Konto A');
        $drugie   = $this->givenAccount('Konto B');
        $user     = $this->givenUser('user@example.com', $pierwsze);
        $drugie->addUser($user);
        $this->em->flush();
        $this->givenMessage($pierwsze, 'Mail z konta A');
        $this->givenMessage($drugie, 'Mail z konta B');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail?account=' . $pierwsze->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Mail z konta A');
        $this->assertSelectorTextNotContains('body', 'Mail z konta B');
    }

    /**
     * `?account=` jest inputem użytkownika, więc cudze ID musi zostać ODRZUCONE, a nie przepuszczone
     * do zapytania. Widok wraca wtedy do „wszystkie moje konta" — nigdy do cudzej poczty.
     */
    public function testObceKontoWQueryStringNieDajeCudzejPoczty(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $user = $this->givenUser('user@example.com', $moje);
        $this->givenMessage($moje, 'Moja wiadomość');
        $this->givenMessage($obce, 'Cudza wiadomość');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail?account=' . $obce->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Cudza wiadomość');
        $this->assertSelectorTextContains('body', 'Moja wiadomość', 'Obce konto ma być zignorowane, a nie wyczyścić listę');
    }

    /**
     * Wejście prosto na `/mail/{id}` (zakładka, link, historia) pokazuje wiadomość W KONTEKŚCIE
     * jej konta: lista obok zawiera sąsiednie maile z tej samej skrzynki, a nie wszystko naraz.
     */
    public function testDeepLinkPokazujeWiadomoscWKontekscieJejKonta(): void {
        $pierwsze = $this->givenAccount('Konto A');
        $drugie   = $this->givenAccount('Konto B');
        $user     = $this->givenUser('user@example.com', $pierwsze);
        $drugie->addUser($user);
        $this->em->flush();
        $message = $this->givenMessage($pierwsze, 'Otwierana wiadomość');
        $this->givenMessage($pierwsze, 'Sąsiad z tego samego konta');
        $this->givenMessage($drugie, 'Mail z innego konta');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Sąsiad z tego samego konta');
        $this->assertSelectorTextNotContains('body', 'Mail z innego konta');
    }

    /**
     * Ramka podglądu istnieje na OBU trasach — również na `/mail`, gdzie nie ma jeszcze czego
     * pokazywać (etap 4.3c).
     *
     * To warunek działania nawigacji, a jego złamanie jest awarią CICHĄ: przy braku ramki (albo
     * literówce w `id`) strona renderuje się bez zarzutu, a Turbo po prostu nie ma czego podmienić
     * i klikanie przestaje działać. Żaden test treści tego nie zauważy, bo treść jest poprawna.
     *
     * Sprawdzamy `id`, a nie `data-turbo-frame` na linkach: `id` to kontrakt między kontrolerem
     * a Turbo (musi być w KAŻDEJ odpowiedzi, do której celuje link), natomiast atrybut na linku
     * jest szczegółem szablonu listy, który w 4.4 przejmuje Live Component.
     */
    public function testRamkaPodgladuJestObecnaNaObuTrasach(): void {
        $account = $this->givenAccount();
        $user    = $this->givenUser('user@example.com', $account);
        $message = $this->givenMessage($account, 'Wiadomość');

        $this->client->loginUser($user);

        $this->client->request('GET', '/mail');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#message', 'Bez ramki na `/mail` klik w listę nie ma czego podmienić');

        $this->client->request('GET', '/mail/' . $message->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#message');
    }

    /**
     * Linki listy celują w ramkę, KTÓRA W TEJ SAMEJ ODPOWIEDZI ISTNIEJE (etap 4.3c).
     *
     * Kontrakt z Turbo ma dwa końce — `id` ramki i `data-turbo-frame` na linku — i awaria każdego
     * z nich jest tak samo cicha: strona renderuje się bez zarzutu, tylko klik przestaje podmieniać
     * podgląd (bez atrybutu Turbo przeładuje całą stronę, przy niezgodnej wartości nie znajdzie celu
     * i nie zrobi nic). Test na sam `id` pilnuje jednego końca, ten pilnuje ZGODNOŚCI obu.
     *
     * Nazwy ramki NIE wpisujemy tu z palca: odczytujemy ją z linku i szukamy w dokumencie. Dzięki
     * temu asercja nie utrwala stałej `message` w drugim miejscu i przeżyje 4.4, gdzie te same linki
     * renderuje Live Component — zmiana nazwy ramki w obu plikach naraz ma zostać zielona, a rozjazd
     * między nimi czerwony.
     */
    public function testLinkiListyCelujaWIstniejacaRamke(): void {
        $account = $this->givenAccount();
        $user    = $this->givenUser('user@example.com', $account);
        $this->givenMessage($account, 'Wiadomość na liście');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail');

        $this->assertResponseIsSuccessful();

        $link = $crawler->filter('main a[href*="/mail/"]')->first();
        $this->assertGreaterThan(0, $link->count(), 'Lista ma zawierać link do wiadomości');

        $target = $link->attr('data-turbo-frame');
        $this->assertNotNull($target, 'Link wiadomości bez `data-turbo-frame` przeładuje całą stronę zamiast ramki');
        $this->assertSelectorExists(
            sprintf('turbo-frame#%s', $target),
            sprintf('Link celuje w ramkę "%s", której nie ma w odpowiedzi — klik nie znajdzie celu', $target),
        );
    }

    /**
     * Wejście prosto na `/mail/{id}` oddaje PEŁNE trzy regiony, a nie sam fragment podglądu
     * (etap 4.3c/4.3d).
     *
     * Świadomie NIE renderujemy skróconej odpowiedzi po nagłówku `Turbo-Frame` — Turbo wycina
     * ramkę z pełnej strony samo, a pełna odpowiedź jest warunkiem działania bez JS-u: zakładki,
     * odświeżenia (F5) i `wstecz` po `data-turbo-action="advance"` trafiają tu BEZ tego nagłówka
     * i muszą dostać kompletną skrzynkę. Test pilnuje, żeby optymalizacja transferu nie wkradła
     * się później i nie zamieniła deep linku w goły fragment.
     */
    public function testDeepLinkOddajePelneTrzyRegionyNieSamFragment(): void {
        $account = $this->givenAccount('Skrzynka testowa');
        $user    = $this->givenUser('user@example.com', $account);
        $message = $this->givenMessage($account, 'Otwierana wiadomość');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Otwierana wiadomość');
        $this->assertSelectorExists('turbo-frame#message');
        // Panel kont i nagłówek listy — regiony, których sam fragment ramki by nie zawierał.
        $this->assertSelectorTextContains('body', 'Skrzynka testowa');
        $this->assertSelectorExists('main aside');
    }

    /**
     * Zapisuje konto IMAP.
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
     * Zapisuje użytkownika, opcjonalnie z dostępem do konta.
     *
     * @param string           $email   Adres e-mail, np. "user@example.com"
     * @param MailAccount|null $account Konto do przypisania albo null (użytkownik bez dostępu)
     * @param list<string>     $roles   Role bez `ROLE_USER`, np. ["ROLE_ADMIN"]
     *
     * @return User Użytkownik z nadanym ID
     */
    private function givenUser(string $email, ?MailAccount $account = null, array $roles = []): User {
        $user = EntityFactory::user($email, $roles);
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
        $message = EntityFactory::message($account, $subject, new \DateTimeImmutable('2026-06-01 08:00'));
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }
}
