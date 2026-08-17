<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Fixtures\EntityFactory;

/**
 * ZACHOWANIE Turbo w prawdziwej przeglądarce (etap 4.3d).
 *
 * Testy z 4.3e pilnują KONTRAKTU — czy serwer wysyła HTML, na którym Turbo może zadziałać
 * (ramka `#message`, `data-turbo-frame` na linkach). Tutaj sprawdzamy, czy Turbo faktycznie
 * działa: czy klik podmienia JEDNĄ kolumnę, czy przewinięcie listy to przeżywa i czy historia
 * przeglądarki zachowuje się jak trzeba. Silnik JS jest do tego niezbędny — `WebTestCase` widzi
 * wyłącznie odpowiedź serwera, identyczną niezależnie od tego, czy Turbo działa, czy nie.
 *
 * Najważniejszy jest `testPrzewiniecieListyPrzezywaKlikWWiadomosc()`: to POWÓD, dla którego
 * ramka objęła sam podgląd, a nie listę (decyzja z 4.3c). Gdyby padł, cały wzorzec byłby do
 * przemyślenia.
 */
class MailboxTurboTest extends BrowserTestCase {

    /** Tyle wiadomości, żeby lista była DŁUŻSZA NIŻ EKRAN — inaczej nie ma czego przewijać. */
    private const MESSAGE_COUNT = 60;

    /**
     * Klik w wiadomość przeładowuje wyłącznie kolumnę podglądu.
     *
     * Mierzymy TOŻSAMOŚCIĄ WĘZŁA, nie liczbą żądań: do węzła listy przypinamy znacznik w JS
     * i sprawdzamy, czy przeżył klik. Jeśli Turbo wymieniłoby całą stronę (albo ramka objęła
     * za dużo), stary węzeł zniknąłby razem ze znacznikiem. To pytanie „czy ten sam element
     * DOM nadal tam jest", czyli dokładnie to, co decyduje o zachowaniu przewinięcia i fokusu.
     */
    public function testKlikPrzeladowujeWylacznieKolumnePodgladu(): void {
        $this->givenMailbox(3);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');

        // Znacznik na liście i na panelu kont — regionach, których klik NIE ma prawa ruszyć.
        $this->browser->executeScript(<<<'JS'
            document.querySelector('main ul').dataset.probe = 'lista';
            document.querySelector('main aside').dataset.probe = 'konta';
            JS);

        $this->clickFirstMessage();

        $this->assertSame(
            'lista',
            $this->browser->executeScript("return document.querySelector('main ul')?.dataset.probe ?? 'ZNIKNAL';"),
            'Klik wymienił węzeł listy — ramka obejmuje za dużo albo Turbo przeładowało całą stronę',
        );
        $this->assertSame(
            'konta',
            $this->browser->executeScript("return document.querySelector('main aside')?.dataset.probe ?? 'ZNIKNAL';"),
            'Klik wymienił panel kont, choć miał ruszyć wyłącznie podgląd',
        );
    }

    /**
     * Przewinięcie listy przeżywa klik w wiadomość — GŁÓWNY ZYSK Z 4.3c.
     *
     * Ramka przeładowuje zawartość (nie morfuje), więc lista owinięta ramką resetowałaby
     * przewinięcie przy każdym kliknięciu. Dlatego ramką objęty jest sam podgląd — a to
     * jedyny sposób, żeby to sprawdzić: pozycja przewinięcia jest stanem DOM, niewidocznym
     * w HTML-u odpowiedzi.
     */
    public function testPrzewiniecieListyPrzezywaKlikWWiadomosc(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');

        $scrolled = (int) $this->browser->executeScript(<<<'JS'
            const list = document.querySelector('main ul').closest('[class*="overflow-y-auto"]');
            list.scrollTop = 400;
            return list.scrollTop;
            JS);

        $this->assertGreaterThan(0, $scrolled, 'Lista się nie przewinęła — za mało wiadomości albo brak overflow-y-auto');

        $this->clickFirstMessage();

        $after = (int) $this->browser->executeScript(<<<'JS'
            const list = document.querySelector('main ul').closest('[class*="overflow-y-auto"]');
            return list.scrollTop;
            JS);

        $this->assertSame(
            $scrolled,
            $after,
            'Przewinięcie listy zresetowało się po kliknięciu — ramka obejmuje listę, czyli dokładnie ta wada, dla której powstało 4.3c',
        );
    }

    /**
     * `data-turbo-action="advance"` wpisuje wiadomość do adresu, a `wstecz` wraca do stanu bez niej.
     *
     * Bez tego ramka podmieniałaby podgląd, zostawiając adres `/mail` — czyli F5 i zakładka
     * gubiłyby otwartą wiadomość, a `wstecz` wychodziłby ze skrzynki zamiast cofać o krok.
     */
    public function testKlikZmieniaAdresAWsteczGoPrzywraca(): void {
        $this->givenMailbox(3);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickFirstMessage();

        $this->browser->wait()->until(
            static fn ($driver): bool => (bool) preg_match('#/mail/\d+#', $driver->getCurrentURL()),
        );
        $this->assertMatchesRegularExpression('#/mail/\d+$#', $this->browser->getCurrentURL());

        $this->browser->back();

        $this->browser->wait()->until(
            static fn ($driver): bool => !preg_match('#/mail/\d+#', $driver->getCurrentURL()),
        );
        $this->assertStringEndsWith('/mail', $this->browser->getCurrentURL());
    }

    /**
     * Linki nagłówka wychodzą z całej strony, a nie do kolumny podglądu.
     *
     * Leżą POZA ramką, więc domyślnie celują w `_top` i `target="_top"` jest zbędny (ustalenie
     * z 4.3c). Reguła odwraca się dla linków WEWNĄTRZ podglądu — treść maila (4.5) i załączniki
     * (4.6) będą go wymagać. Ten test pilnuje, żeby dzisiejszy stan nie zepsuł się po cichu.
     */
    public function testLinkiNaglowkaNieWpadajaDoKolumnyPodgladu(): void {
        $this->givenMailbox(3);
        $this->loginAs('user@example.com');

        // Zaczynamy od OTWARTEJ wiadomości: logo prowadzi do `/mail`, więc dopiero z podglądem
        // na ekranie widać różnicę między nawigacją całej strony a podmianą samej ramki.
        $this->browser->request('GET', '/mail');
        $this->clickFirstMessage();

        $this->browser->executeScript("document.querySelector('header a[href$=\"/mail\"]').click();");

        $this->browser->wait()->until(
            static fn ($driver): bool => str_ends_with($driver->getCurrentURL(), '/mail'),
        );

        // Cała strona wróciła do stanu „bez wybranej wiadomości" — gdyby link trafił do ramki,
        // adres by się zmienił, ale podgląd zostałby wypełniony poprzednią wiadomością.
        $this->assertSelectorNotExists(
            'turbo-frame#message h1',
            'Nagłówek ramki przetrwał nawigację — link wpadł do kolumny podglądu zamiast przeładować stronę',
        );
    }

    /**
     * ŚWIADOMA LUKA do 4.4: nawigacja ramkowa gubi podświetlenie wiersza, pełny render nie.
     *
     * Klasa aktywnego wiersza liczy się z danych (`message.id == item.id` w `_list.html.twig`),
     * ale klik podmienia WYŁĄCZNIE ramkę podglądu — lista zostaje ta sama, więc nigdy nie dostaje
     * nowego `message`. Serwer renderuje ją poprawnie, tylko Turbo tę część odpowiedzi odrzuca.
     *
     * ROZSTRZYGNIĘCIE 4.3d (plan dopuszczał dwa wyniki): podświetlenie ZNIKA ZUPEŁNIE — nie
     * zostaje na poprzednim wierszu. Także po `wstecz`, bo snapshot w historii pochodzi ze stanu
     * już pozbawionego klasy. Wniosek dla 4.4: wystarczy `LiveProp` z ID otwartej wiadomości,
     * BEZ nasłuchu `popstate` — komponent odtworzy stan z adresu, a odtwarzać trzeba brak klasy,
     * a nie rozjazd między wierszami.
     *
     * Test pilnuje OBU stron tej luki naraz. Gdy 4.4 ją domknie, pierwsza asercja zacznie padać
     * — i to jest jej zadanie: ma wtedy zniknąć razem z luką, a nie zostać po cichu osłabiona.
     */
    public function testPodswietlenieWierszaGinieNaRamceAleDzialaPoPelnymRenderze(): void {
        $this->givenMailbox(5);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickFirstMessage();

        $this->assertSame(
            -1,
            $this->activeRowIndex(),
            'Podświetlenie po kliknięciu jednak działa — luka domknięta, ten test i opis w CLAUDE.md są do usunięcia',
        );

        // Ten sam adres, ale wpisany od nowa: serwer oddaje komplet trzech regionów i klasa wraca.
        $current = $this->browser->getCurrentURL();
        $this->browser->request('GET', $current);

        $this->assertGreaterThanOrEqual(
            0,
            $this->activeRowIndex(),
            'Pełny render nie podświetla wiersza — to już nie luka Turbo, tylko błąd w `_list.html.twig`',
        );
    }

    /**
     * Numer podświetlonego wiersza listy albo -1, gdy żaden nie jest aktywny.
     *
     * Świadomie po KLASIE, nie po `aria-current`: klasa jest dziś jedynym nośnikiem tego stanu
     * (zob. `_list.html.twig`), więc test mierzy to, co widzi użytkownik.
     *
     * @return int Indeks od zera, np. 1 dla drugiego wiersza; -1 gdy brak podświetlenia
     */
    private function activeRowIndex(): int {
        return (int) $this->browser->executeScript(<<<'JS'
            return (function () {
                var rows = Array.prototype.slice.call(document.querySelectorAll('main ul li'));
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].className.indexOf('bg-slate-100') !== -1) {
                        return i;
                    }
                }
                return -1;
            })();
            JS);
    }

    /**
     * Klika pierwszą wiadomość i czeka, aż podgląd faktycznie się wypełni.
     *
     * Czekamy na `h1` WEWNĄTRZ ramki, bo samo kliknięcie wraca natychmiast — Turbo pobiera
     * odpowiedź asynchronicznie i bez tego asercje czytałyby DOM sprzed podmiany.
     */
    private function clickFirstMessage(): void {
        $this->browser->executeScript("document.querySelector('main ul a').click();");
        $this->browser->waitForVisibility('turbo-frame#message h1');
    }

    /**
     * Zakłada konto z użytkownikiem i zadaną liczbą wiadomości — jednym zapisem.
     *
     * `dama` jest wyłączona (zob. `BrowserTestCase`), więc `flush()` idzie realnym COMMIT-em;
     * przy 60 wiadomościach osobny zapis na każdą byłby 60 round-tripami zamiast jednego.
     *
     * @param int $messages Liczba wiadomości na koncie, np. 60
     */
    private function givenMailbox(int $messages): void {
        $account = EntityFactory::account('Skrzynka testowa');
        $user    = EntityFactory::user('user@example.com');
        $account->addUser($user);

        $this->em->persist($account);
        $this->em->persist($user);

        for ($i = 1; $i <= $messages; ++$i) {
            $this->em->persist(EntityFactory::message(
                $account,
                sprintf('Wiadomość numer %d', $i),
                new \DateTimeImmutable(sprintf('2026-06-01 08:00 +%d minutes', $i)),
            ));
        }

        $this->em->flush();
    }
}
