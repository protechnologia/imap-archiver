<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Fixtures\EntityFactory;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Baza testów wykonywanych w PRAWDZIWEJ przeglądarce (etap 4.3d).
 *
 * Po co w ogóle przeglądarka: Turbo to JavaScript, a `WebTestCase` widzi wyłącznie HTML wysłany
 * przez serwer. Testy z 4.3e pilnują więc tylko KONTRAKTU (czy ramka i `data-turbo-frame` są na
 * swoim miejscu) — czy klik faktycznie podmienia jedną kolumnę i czy przewinięcie listy to
 * przeżywa, może odpowiedzieć dopiero silnik przeglądarki.
 *
 * DLACZEGO `dama` JEST TU WYŁĄCZONA. Reszta zestawu działa w jednym procesie: `WebTestCase` woła
 * kernel bezpośrednio, więc aplikacja siedzi WEWNĄTRZ transakcji otwartej przez bundla i widzi
 * jej niezacommitowane dane. Tutaj między testem a aplikacją jest granica procesu — Chromium
 * rozmawia po HTTP z osobnym serwerem (usługa `php-test`), który ma własne połączenie do bazy.
 * Postgres izoluje sesje, więc serwer nie zobaczyłby ani jednego wiersza wstawionego w teście
 * i każda lista byłaby pusta. Danych nie da się „pożyczyć" przez granicę procesu — muszą być
 * realnie ZACOMMITOWANE, a skoro rollback już po nas nie posprząta, robimy to jawnie.
 *
 * Stan początkowy odtwarzamy przed KAŻDYM testem (`TRUNCATE`), a nie po nim: test, który padnie
 * w połowie, zostawia śmieci, i to następny test ma prawo zastać czysto — nie odwrotnie.
 *
 * Bezpieczeństwo: piszemy do `app_test` (usługa `php-test` ma `APP_ENV=test`, a `dbname_suffix`
 * z `when@test` dokleja sufiks), nigdy do deweloperskiej `app`. `assertTestDatabase()` pilnuje
 * tego przy każdym resecie — `TRUNCATE` na złej bazie skasowałby realny import.
 */
abstract class BrowserTestCase extends PantherTestCase {

    protected Client $browser;
    protected EntityManagerInterface $em;

    protected function setUp(): void {
        // Musi paść PRZED bootem kernela: inaczej bundle podmieni sterownik i owinie
        // połączenie w transakcję, której serwer po drugiej stronie HTTP i tak nie zobaczy.
        StaticDriver::setKeepStaticConnections(false);

        parent::setUp();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->assertTestDatabase();
        $this->resetDatabase();

        $this->browser = static::createPantherClient();

        // Panther współdzieli JEDNĄ przeglądarkę między testami w klasie, więc ciasteczko sesji
        // przeżywa granicę testu — a `resetDatabase()` właśnie skasował użytkownika, do którego
        // ta sesja się odwołuje. Drugi test wchodziłby wtedy na `/login` jako „zalogowany",
        // dostawał przekierowanie na `/mail` i nie znajdował formularza.
        $this->browser->getCookieJar()->clear();
    }

    protected function tearDown(): void {
        // Sprzątamy TAKŻE po sobie, nie tylko przed sobą. Reszta zestawu działa na rollbacku
        // `dama` i zakłada pustą bazę, a nasze dane idą realnym COMMIT-em — zostawione tu konto
        // wywalałoby na `UNIQUE(email)` każdy test, który zakłada użytkownika o tym samym
        // adresie. Reset w `setUp()` chroni testy przeglądarkowe przed sobą nawzajem, ten
        // chroni przed nimi CAŁY POZOSTAŁY zestaw.
        $this->resetDatabase();

        parent::tearDown();

        // Przywracamy stan dla reszty zestawu — `StaticDriver` jest statyczny, więc bez tego
        // testy uruchomione PO przeglądarkowych straciłyby izolację transakcyjną.
        StaticDriver::setKeepStaticConnections(true);
    }

    /**
     * Loguje użytkownika PRZEZ FORMULARZ, w prawdziwej przeglądarce.
     *
     * `KernelBrowser::loginUser()` jest tu niedostępny z tego samego powodu, dla którego
     * wyłączamy `dama`: sesję trzyma przeglądarka po drugiej stronie HTTP, a nie proces testowy.
     * Hasło jest wspólne dla wszystkich fikstur — `EntityFactory::user()` zapisuje jego hash.
     *
     * Stateless CSRF nie wymaga tu żadnej obsługi: token jest w formularzu literałem, który JS
     * podmienia w przeglądarce — czyli dokładnie to, co robi prawdziwy użytkownik (w testach
     * curl-em trzeba było podawać placeholder ręcznie).
     *
     * @param string $email Adres zalogowanego użytkownika, np. "user@example.com"
     */
    protected function loginAs(string $email): void {
        $this->browser->request('GET', '/login');

        $form = $this->browser->getCrawler()->filter('form')->form([
            '_username' => $email,
            '_password' => EntityFactory::PASSWORD,
        ]);

        $this->browser->submit($form);

        // Czekamy na OPUSZCZENIE `/login`, a nie na pojawienie się `main`: strona logowania też
        // ma `main`, więc warunek spełniłby się natychmiast, jeszcze przed przekierowaniem, i
        // asercje testu czytałyby wciąż formularz.
        $this->browser->wait()->until(
            static fn ($driver): bool => !str_contains($driver->getCurrentURL(), '/login'),
        );
    }

    /**
     * Czyści tabele, na których operują testy przeglądarkowe.
     *
     * `TRUNCATE ... RESTART IDENTITY CASCADE` zamiast `DELETE`: zeruje też sekwencje, więc ID
     * nie rosną w nieskończoność między testami, a `CASCADE` zdejmuje z głowy kolejność tabel
     * powiązanych kluczami obcymi (`mail_account_user`, `attachment`).
     */
    protected function resetDatabase(): void {
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE message, mail_account, "user" RESTART IDENTITY CASCADE',
        );
    }

    /**
     * Zabezpieczenie przed `TRUNCATE` na bazie deweloperskiej.
     *
     * Kosztuje jedno zapytanie na test, a chroni przed pomyłką, której skutkiem jest skasowanie
     * indeksu realnego archiwum — konfiguracja bazy jest tu rozstrzygana w kilku miejscach naraz
     * (`APP_ENV` usługi, `dbname_suffix`, `DATABASE_URL`) i pomylenie ich jest łatwe.
     */
    private function assertTestDatabase(): void {
        $database = $this->em->getConnection()->fetchOne('SELECT current_database()');

        $this->assertStringEndsWith(
            '_test',
            (string) $database,
            sprintf('Testy przeglądarkowe czyszczą bazę przez TRUNCATE — baza "%s" nie wygląda na testową', $database),
        );
    }
}
