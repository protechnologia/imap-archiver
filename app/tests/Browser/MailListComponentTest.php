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
     * sztywne czekanie, bo nie zgaduje, ile potrwa odpowiedź. Pierwsze krótkie odczekanie daje
     * `debounce(300)` szansę wystartować; bez niego warunek bywa spełniony, zanim cokolwiek ruszy.
     */
    private function waitForRerender(): void {
        usleep(400_000);

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
