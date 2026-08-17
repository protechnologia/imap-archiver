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
     * Klik podświetla kliknięty wiersz — bez przeładowania listy (etap 4.4).
     *
     * To DOMKNIĘCIE LUKI z 4.3: wtedy klik podmieniał wyłącznie ramkę podglądu, więc lista nigdy
     * nie dostawała nowego `message` i podświetlenie znikało zupełnie (zmierzone w 4.3d — stąd
     * wniosek, że wystarczy `LiveProp` z ID i nie trzeba nasłuchu `popstate`). Teraz klik robi
     * dwie rzeczy naraz: Turbo wymienia ramkę, a akcja live ustawia `messageId` i komponent
     * morfuje wiersz w miejscu.
     *
     * Sprawdzamy DRUGI wiersz, nie pierwszy: przy pierwszym indeks 0 mógłby wyjść przypadkiem
     * (np. gdyby szablon podświetlał domyślnie początek listy), a 1 nie ma jak paść przypadkiem.
     */
    public function testKlikPodswietlaWlasciwyWiersz(): void {
        $this->givenMailbox(5);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');

        $this->assertSame(-1, $this->activeRowIndex(), 'Na wejściu bez wybranej wiadomości nic nie ma być podświetlone');

        $this->clickMessage(1);

        $this->assertSame(
            1,
            $this->activeRowIndex(),
            'Podświetlenie nie trafiło na kliknięty wiersz — `messageId` nie doszedł do komponentu',
        );
    }

    /**
     * Deep link i odświeżenie też podświetlają właściwy wiersz.
     *
     * Ta ścieżka działała już w 4.3 (pełny render oddaje komplet trzech regionów), ale po
     * przeniesieniu listy do komponentu przechodzi zupełnie inaczej: `messageId` wjeżdża teraz
     * jako `LiveProp` z `_list.html.twig`, a nie zmienną szablonu. Warto pilnować obu wejść —
     * gdyby przekazanie się urwało, klik nadal by działał, a zakładka i F5 już nie.
     */
    public function testDeepLinkPodswietlaWlasciwyWiersz(): void {
        $this->givenMailbox(5);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickMessage(1);

        // Ten sam adres wpisany od nowa — pełna nawigacja, komponent budowany od zera.
        $this->browser->request('GET', $this->browser->getCurrentURL());

        $this->assertSame(
            1,
            $this->activeRowIndex(),
            'Po pełnym renderze podświetlenie zniknęło — `messageId` nie dojechał z kontrolera do komponentu',
        );
    }

    /**
     * Numer podświetlonego wiersza listy albo -1, gdy żaden nie jest aktywny.
     *
     * Rozpoznajemy po BRAKU `border-transparent`, a nie po kolorze tła: kolory zaznaczenia to
     * decyzja wizualna, która może się zmienić (i zmieniła się już raz), a obecność paska po lewej
     * jest samą definicją „ten wiersz jest aktywny". Test nie ma padać przez zmianę odcienia.
     *
     * Klasa jest dziś jedynym nośnikiem tego stanu (nie ma `aria-current`), więc mierzymy to,
     * co widzi użytkownik.
     *
     * @return int Indeks od zera, np. 1 dla drugiego wiersza; -1 gdy brak podświetlenia
     */
    private function activeRowIndex(): int {
        return (int) $this->browser->executeScript(<<<'JS'
            return (function () {
                var rows = Array.prototype.slice.call(document.querySelectorAll('main ul li'));
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].className.indexOf('border-l-transparent') === -1) {
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
        $this->clickMessage(0);
    }

    /**
     * Klika wiadomość o podanym numerze na liście i czeka na wypełniony podgląd.
     *
     * @param int $nth Indeks od zera, np. 1 dla drugiego wiersza
     */
    private function clickMessage(int $nth): void {
        $this->browser->executeScript(sprintf("document.querySelectorAll('main ul a')[%d].click();", $nth));
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
