<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\MailAccount;
use App\Tests\Fixtures\EntityFactory;

/**
 * Reaktywność listy: szukanie i paginacja bez przeładowania strony (etap 4.4).
 *
 * Osobny plik od `MailboxTurboTest`, bo to inny mechanizm: tam Turbo i nawigacja między regionami,
 * tu Live Component i stan wewnątrz jednej kolumny. Wspólne jest tylko to, że jedno i drugie
 * wymaga prawdziwej przeglądarki — `WebTestCase` dostałby zawsze pierwszy render, bo żądania
 * komponentu lecą JS-em.
 *
 * `MessageRepository::searchPage()` ma własne testy integracyjne (etap 4.1), więc NIE powtarzamy
 * tu sprawdzania samego zapytania — wielkości liter, `ESCAPE`, sortowania `NULLS LAST`. Tutaj
 * pilnujemy wyłącznie tego, czego bez przeglądarki nie widać: czy wpisanie frazy w ogóle dociera
 * do komponentu i czy stan listy zachowuje się sensownie między akcjami.
 */
class MailListComponentTest extends BrowserTestCase {

    /** Ponad jedna strona (`MailList::PER_PAGE` = 50), żeby paginacja miała co pokazać. */
    private const MESSAGE_COUNT = 60;

    /**
     * Wpisanie frazy zawęża listę bez przeładowania strony.
     *
     * Sprawdzamy TOŻSAMOŚĆ WĘZŁA nagłówka: komponent renderuje się morfem, więc elementy, które
     * się nie zmieniły, mają zostać te same. Gdyby zamiast tego następowała pełna nawigacja,
     * znacznik przypięty w JS zniknąłby razem ze starym DOM — a wtedy przewinięcie i fokus
     * w polu szukania też by ginęły przy każdym znaku.
     */
    public function testSzukanieZawezaListeBezPrzeladowaniaStrony(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->browser->executeScript("document.querySelector('main section header').dataset.probe = 'przed';");

        $this->search('numer 7');

        $this->assertSame(
            'przed',
            $this->browser->executeScript("return document.querySelector('main section header')?.dataset.probe ?? 'ZNIKNAL';"),
            'Szukanie przeładowało stronę zamiast zmorfować komponent — zginąłby fokus w polu i przewinięcie listy',
        );

        // „numer 7" trafia w 7, 17, 27, 37, 47, 57 — czyli mniej niż wszystkie, ale więcej niż nic.
        $found = $this->rowCount();
        $this->assertGreaterThan(0, $found, 'Fraza pasująca do tematów nie zwróciła żadnej wiadomości');
        $this->assertLessThan(self::MESSAGE_COUNT, $found, 'Lista nie została zawężona — fraza nie doszła do komponentu');
    }

    /**
     * Fraza bez trafień daje komunikat, a nie pustą kolumnę.
     *
     * Pusty wynik jest poprawnym stanem, ale bez komunikatu wygląda jak zepsuta aplikacja —
     * użytkownik nie wie, czy nic nie znaleziono, czy lista się nie wczytała.
     */
    public function testFrazaBezTrafienDajeKomunikat(): void {
        $this->givenMailbox(5);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->search('zupelnie-nieistniejaca-fraza');

        $this->assertSame(0, $this->rowCount());
        $this->assertSelectorTextContains('main section', 'Brak wiadomości pasujących');
    }

    /**
     * Przyciski paginacji przechodzą między stronami i wracają.
     *
     * Numer strony jest `LiveProp` zmienianym akcjami, a nie parametrem w adresie (świadomie —
     * zob. komponent), więc jedynym sposobem sprawdzenia jest kliknięcie w przeglądarce.
     */
    public function testPaginacjaPrzechodziMiedzyStronami(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');

        $this->assertSelectorTextContains('main section nav', '1 z 2');
        $firstOnPageOne = $this->firstRowSubject();

        $this->clickPagination('Następna');

        $this->assertSelectorTextContains('main section nav', '2 z 2');
        $this->assertNotSame($firstOnPageOne, $this->firstRowSubject(), 'Druga strona pokazuje te same wiadomości co pierwsza');

        $this->clickPagination('Poprzednia');

        $this->assertSelectorTextContains('main section nav', '1 z 2');
        $this->assertSame($firstOnPageOne, $this->firstRowSubject());
    }

    /**
     * Zawężenie frazą wraca na pierwszą stronę.
     *
     * Bez tego szukanie z otwartej strony 2 pokazywałoby pustkę, gdy wynik ma tylko jedną stronę —
     * użytkownik zobaczyłby „brak wiadomości" mimo istniejących trafień.
     */
    public function testSzukanieWracaNaPierwszaStrone(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickPagination('Następna');
        $this->assertSelectorTextContains('main section nav', '2 z 2');

        $this->search('numer 3');

        $this->assertGreaterThan(0, $this->rowCount(), 'Po zawężeniu lista jest pusta — komponent został na nieistniejącej stronie');
    }

    /**
     * Paginacja znika, gdy wyniki mieszczą się na jednej stronie.
     *
     * To jedyny sygnał, że lista jest kompletna; widoczne „1 z 1" sugerowałoby, że gdzieś jest
     * dalszy ciąg.
     */
    public function testPaginacjaZnikaPrzyJednejStronie(): void {
        $this->givenMailbox(3);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');

        $this->assertSelectorNotExists('main section nav');
    }

    /**
     * Po zawężeniu frazą podświetlony jest wiersz OTWARTEJ wiadomości, a nie ten sam co przedtem.
     *
     * Para z `testPaginacjaNiePodswietlaCudzegoWiersza` — ten sam defekt widziany przez dwa
     * wejścia, bo obie akcje kończą się re-renderem komponentu.
     *
     * SZEW MIĘDZY DWOMA MECHANIZMAMI. Po kliknięciu klasę aktywnego wiersza ustawia kontroler
     * Stimulusa, w DOM — serwer o tym nie wie, bo klik jest czystą nawigacją Turbo i komponent nie
     * dostaje żadnego żądania. Ten test pilnuje, co się z tą klasą dzieje przy najbliższym
     * re-renderze listy.
     *
     * ZŁAPAŁ REALNY BŁĄD: podświetlony był „numer 56". Live Component zapamiętuje zmiany DOM
     * zrobione spoza siebie (`ExternalMutationTracker`) i po morfie odtwarza je na elemencie
     * z TEGO SAMEGO MIEJSCA, więc klasa trzymała się POZYCJI, nie wiadomości. Naprawił to
     * `data-skip-morph` na `<ul>` — wiersze są wymieniane hurtem, nie ma czego odtwarzać.
     *
     * Dlatego asercja porównuje TEMAT podświetlonego wiersza, nie jego indeks — test na indeks
     * przechodził z przypadku, gdy otwarta wiadomość zostawała na tym samym miejscu, a to
     * najczęstszy układ. Fikstury dobrane tak, żeby pozycja SIĘ ZMIENIŁA: lista jest sortowana
     * malejąco po dacie, czyli 60, 59, 58, 57…, a po zawężeniu do „numer 5" (trafia 59…50 oraz 5)
     * klikany „numer 57" przesuwa się z indeksu 3 na 2.
     */
    public function testSzukanieNiePodswietlaCudzegoWiersza(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickMessage(3);

        $otwarta = $this->activeRowSubject();
        $this->assertSame('Wiadomość numer 57', $otwarta, 'Klik nie podświetlił wiersza — test mierzy nie to, co trzeba');

        $this->search('numer 5');

        $this->assertSame(
            $otwarta,
            $this->activeRowSubject(),
            'Po zawężeniu podświetlony jest inny temat niż otwarty w podglądzie — klasa została na pozycji',
        );
    }

    /**
     * Na stronie bez otwartej wiadomości NIC nie jest podświetlone.
     *
     * Ta sama przyczyna co w teście wyżej, ostrzejszy objaw: na stronie 2 otwartej wiadomości nie
     * ma na liście w ogóle, a mimo to świecił się „numer 9" — użytkownik widział podgląd jednej
     * wiadomości i wskazaną na liście inną.
     *
     * Ten przypadek jest czulszy niż tamten: samo poprawne dopasowanie węzłów by go nie uratowało.
     * Sprawdziliśmy to — po dodaniu `id` na wierszach test szukania zzieleniał, a ten dalej padał,
     * bo dopasowanie po `id` działa tylko wtedy, gdy element istnieje po OBU stronach morfa.
     */
    public function testPaginacjaNiePodswietlaCudzegoWiersza(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickMessage(1);
        $this->clickPagination('Następna');

        $this->assertSame(
            '',
            $this->activeRowSubject(),
            'Otwartej wiadomości nie ma na tej stronie, a mimo to któryś wiersz jest podświetlony',
        );
    }

    /**
     * Powrót na stronę z otwartą wiadomością znowu ją podświetla.
     *
     * STRAŻNIK, nie test defektu — pilnuje, żeby naprawa dwóch testów obok nie poszła najprostszą
     * drogą, czyli gaszenia wszystkich podświetleń przy re-renderze. Taka „naprawa" zazieleniłaby
     * tamte dwa (nigdzie nie świeci się cudzy wiersz), a zabrałaby funkcję — użytkownik po powrocie
     * ze strony 2 nie widziałby, którą wiadomość ma otwartą.
     *
     * Przechodził już przed naprawą, ale Z PRZYPADKU: otwarta wiadomość wracała na tę samą pozycję,
     * więc klasa przeniesiona przez `ExternalMutationTracker` lądowała tam, gdzie trzeba. Teraz
     * przechodzi z powodu — `rowTargetConnected()` wyprowadza stan każdego wiersza z adresu.
     */
    public function testPaginacjaPoPowrociePodswietlaNaszaWiadomosc(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickMessage(1);

        $otwarta = $this->activeRowSubject();
        $this->assertSame('Wiadomość numer 59', $otwarta, 'Klik nie podświetlił wiersza — test mierzy nie to, co trzeba');

        $this->clickPagination('Następna');
        $this->clickPagination('Poprzednia');

        $this->assertSame(
            $otwarta,
            $this->activeRowSubject(),
            'Po powrocie na stronę 1 otwarta wiadomość nie jest podświetlona, choć podgląd wciąż ją pokazuje',
        );
    }

    /**
     * Zdjęcie frazy przywraca podświetlenie otwartej wiadomości.
     *
     * Odpowiednik `testPaginacjaPoPowrociePodswietlaNaszaWiadomosc` dla drugiego wejścia i z tą
     * samą rolą strażnika: pilnuje, żeby podświetlenie nie gasło przy każdym re-renderze.
     *
     * Wyczyszczenie pola to PEŁNY OBIEG, nie cofnięcie zmiany — komponent leci z pustym `query`
     * po całą pierwszą stronę. Wracamy więc do tego samego stanu, w którym byliśmy po kliknięciu,
     * i otwarta wiadomość ma być z powrotem zaznaczona. Nawigacja („1 z 2") potwierdza, że lista
     * faktycznie wróciła do pełnego zestawu, a nie została na zawężonej jednej stronie.
     */
    public function testSzukaniePoZdjeciuFrazyPodswietlaNaszaWiadomosc(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickMessage(3);

        $otwarta = $this->activeRowSubject();
        $this->assertSame('Wiadomość numer 57', $otwarta, 'Klik nie podświetlił wiersza — test mierzy nie to, co trzeba');

        $this->search('numer 5');
        $this->search('');

        $this->assertSelectorTextContains('main section nav', '1 z 2', 'Lista nie wróciła do pełnego zestawu po zdjęciu frazy');
        $this->assertSame(
            $otwarta,
            $this->activeRowSubject(),
            'Po zdjęciu frazy otwarta wiadomość nie jest podświetlona, choć podgląd wciąż ją pokazuje',
        );
    }

    /**
     * Fraza wykluczająca otwartą wiadomość gasi podświetlenie, a jej zdjęcie je przywraca.
     *
     * JEDYNA ŚCIEŻKA, W KTÓREJ WIERSZ ZNIKA Z DOM I WRACA — i dlatego ten test istnieje osobno.
     * W pozostałych przypadkach wiersz otwartej wiadomości jest w liście przez cały czas, więc
     * wystarczy, żeby ktokolwiek poprawnie przełożył na niego klasę. Tutaj element jest niszczony
     * i tworzony od nowa, co wyklucza wszystkie rozwiązania oparte na PAMIĘTANIU stanu elementu
     * (`ExternalMutationTracker`, `data-live-preserve`, dopasowanie po `id`) — przechodzi wyłącznie
     * takie, które wyprowadza podświetlenie z adresu przy każdym pojawieniu się wiersza, czyli
     * nasze `rowTargetConnected()`.
     *
     * „numer 1" trafia w 1 i 10-19, więc klikany „numer 57" wypada z wyników w całości.
     */
    public function testFrazaBezOtwartejWiadomosciGasiIPrzywracaPodswietlenie(): void {
        $this->givenMailbox(self::MESSAGE_COUNT);
        $this->loginAs('user@example.com');

        $this->browser->request('GET', '/mail');
        $this->clickMessage(3);

        $otwarta = $this->activeRowSubject();
        $this->assertSame('Wiadomość numer 57', $otwarta, 'Klik nie podświetlił wiersza — test mierzy nie to, co trzeba');

        $this->search('numer 1');

        $this->assertSame(
            '',
            $this->activeRowSubject(),
            'Otwartej wiadomości nie ma wśród trafień, a mimo to któryś wiersz jest podświetlony',
        );

        $this->search('');

        $this->assertSame(
            $otwarta,
            $this->activeRowSubject(),
            'Wiersz wrócił na listę, ale bez podświetlenia — podgląd wciąż pokazuje tę wiadomość',
        );
    }

    /**
     * Klika wiadomość o podanym numerze na liście i czeka na wypełniony podgląd.
     *
     * Czekamy na `h1` WEWNĄTRZ ramki, bo samo kliknięcie wraca natychmiast — Turbo pobiera
     * odpowiedź asynchronicznie i bez tego asercje czytałyby DOM sprzed podmiany.
     *
     * @param int $nth Indeks od zera, np. 1 dla drugiego wiersza
     */
    private function clickMessage(int $nth): void {
        $this->browser->executeScript(sprintf("document.querySelectorAll('main ul a')[%d].click();", $nth));
        $this->browser->waitForVisibility('turbo-frame#message h1');
    }

    /**
     * Temat podświetlonego wiersza listy albo pusty string, gdy żaden nie jest aktywny.
     *
     * TEMAT, a nie indeks — to jest sedno tych testów. Podświetlenie ma wskazywać wiadomość,
     * a nie miejsce na liście, a po re-renderze komponentu te dwie rzeczy potrafią się rozjechać.
     * `MailboxTurboTest` mierzy indeksem i słusznie: tam lista się nie zmienia, więc pozycja
     * jednoznacznie identyfikuje wiersz.
     *
     * Aktywny wiersz rozpoznajemy po BRAKU `border-l-transparent`, a nie po kolorze tła: kolory
     * to decyzja wizualna, która może się zmienić, a pasek po lewej jest samą definicją „aktywny".
     *
     * @return string Temat, np. "Wiadomość numer 57"; pusty string gdy nic nie jest podświetlone
     */
    private function activeRowSubject(): string {
        return (string) $this->browser->executeScript(<<<'JS'
            return (function () {
                var rows = Array.prototype.slice.call(document.querySelectorAll('main section ul li'));
                for (var i = 0; i < rows.length; i++) {
                    if (rows[i].className.indexOf('border-l-transparent') === -1) {
                        return rows[i].querySelector('a span span').textContent.trim();
                    }
                }
                return '';
            })();
            JS);
    }

    /**
     * Wpisuje frazę w pole szukania i czeka, aż komponent się przerenderuje.
     *
     * Zdarzenie `input` zamiast `sendKeys()`: pole jest wiązane przez `data-model`, a kontroler
     * live nasłuchuje właśnie tego zdarzenia. Czekamy dłużej niż `debounce(300)` z szablonu,
     * inaczej asercje czytałyby listę sprzed odpowiedzi.
     *
     * @param string $phrase Szukana fraza, np. "numer 7"
     */
    private function search(string $phrase): void {
        $this->browser->executeScript(sprintf(
            <<<'JS'
                const input = document.querySelector('main section input[data-model]');
                input.value = %s;
                input.dispatchEvent(new Event('input', {bubbles: true}));
                JS,
            json_encode($phrase, JSON_THROW_ON_ERROR),
        ));

        $this->waitForRerender();
    }

    /**
     * Klika przycisk paginacji i czeka na nową stronę.
     *
     * @param string $label Napis na przycisku: "Następna" albo "Poprzednia"
     */
    private function clickPagination(string $label): void {
        $this->browser->executeScript(sprintf(
            <<<'JS'
                const button = Array.from(document.querySelectorAll('main section nav button'))
                    .find((el) => el.textContent.trim() === %s);
                button.click();
                JS,
            json_encode($label, JSON_THROW_ON_ERROR),
        ));

        $this->waitForRerender();
    }

    /**
     * Czeka, aż komponent skończy przetwarzać żądanie.
     *
     * Kontroler live oznacza element atrybutem `busy` na czas żądania — to pewniejszy sygnał niż
     * sztywne czekanie, bo nie zgaduje, ile potrwa odpowiedź. Pierwsze odczekanie daje
     * `debounce(300)` szansę wystartować; bez niego warunek bywa spełniony, zanim cokolwiek ruszy.
     *
     * 600 ms, czyli dwukrotność debounce'u: przy 400 ms test szukania potrafił zobaczyć listę
     * sprzed odpowiedzi i oblać z pełnym kompletem 50 wierszy. Tego akurat czasu nie da się
     * zastąpić czekaniem na sygnał — przez pierwsze 300 ms komponent celowo NIC nie robi.
     */
    private function waitForRerender(): void {
        usleep(600_000);

        $this->browser->wait()->until(
            static fn ($driver): bool => $driver->executeScript(
                "return document.querySelector('main section [data-live-name-value]')?.hasAttribute('busy') === false;",
            ) === true,
        );
    }

    /**
     * @return int Liczba widocznych wierszy listy, np. 6
     */
    private function rowCount(): int {
        return (int) $this->browser->executeScript("return document.querySelectorAll('main section ul li').length;");
    }

    /**
     * @return string Temat pierwszej wiadomości na liście, np. "Wiadomość numer 60"
     */
    private function firstRowSubject(): string {
        return (string) $this->browser->executeScript(
            "return document.querySelector('main section ul li a span span')?.textContent.trim() ?? '';",
        );
    }

    /**
     * Zakłada konto z użytkownikiem i zadaną liczbą wiadomości — jednym zapisem.
     *
     * Tematy niosą numer (`Wiadomość numer 7`), żeby dało się szukać czegoś konkretnego i policzyć
     * spodziewane trafienia.
     *
     * @param int $messages Liczba wiadomości na koncie, np. 60
     */
    private function givenMailbox(int $messages): MailAccount {
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

        return $account;
    }
}
