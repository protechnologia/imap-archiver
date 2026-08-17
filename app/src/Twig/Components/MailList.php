<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\MailAccount;
use App\Entity\User;
use App\Model\MessageListPage;
use App\Repository\MailAccountRepository;
use App\Repository\MessageRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Środkowa kolumna skrzynki: lista wiadomości z szukaniem i paginacją (etap 4.4).
 *
 * Komponent stoi OBOK ramki podglądu — nie w niej i nie w ramce własnej. To nie jest szczegół
 * implementacji, tylko sedno wzorca z 4.3: ramka PRZEŁADOWUJE zawartość (gubi przewinięcie, fokus
 * i zaznaczenie), a Live Component MORFUJE, czyli podmienia wyłącznie to, co się zmieniło.
 * Owinięcie go ramką odebrałoby dokładnie tę zaletę, dla której tu jest.
 *
 * Dwa źródła prawdy, których NIE wolno pomylić: podpis `LiveProp` mówi „tę wartość wystawił mój
 * serwer", a `MailAccountRepository` — „ten użytkownik ma do niej prawo". Pierwsze nie zastępuje
 * drugiego, dlatego `$accountId` nigdy nie trafia wprost do zapytania (zob. `accessibleAccounts()`).
 */
#[AsLiveComponent]
class MailList {
    use DefaultActionTrait;

    /** Rozmiar strony listy — przeniesiony z `MailController` (etap 4.1). */
    private const PER_PAGE = 50;

    /**
     * Konto wybrane w panelu po lewej; null = wszystkie konta użytkownika.
     *
     * USTAWIANE Z ZEWNĄTRZ (`:accountId` w `mail/_list.html.twig`), potem odsyłane w każdym
     * żądaniu komponentu. Non-writable, czyli podpisane checksumem z `APP_SECRET` — użytkownik go
     * nie podmieni. Mimo to nie ufamy mu na słowo: prawo dostępu rozstrzyga `accessibleAccounts()`.
     */
    #[LiveProp]
    public ?int $accountId = null;

    /**
     * Wiadomość otwarta w podglądzie — podświetla wiersz PRZY RENDERZE strony.
     *
     * USTAWIANE Z ZEWNĄTRZ (`:messageId` w `mail/_list.html.twig`), czyli przy pełnym renderze:
     * deep link, F5, zmiana konta. Klik w wiersz tej wartości NIE zmienia — przestawia klasę
     * kontroler Stimulusa `active-row`, w DOM i bez żądania.
     *
     * Ten podział jest ŚWIADOMY i wynika z pomiaru. Próbowaliśmy zrobić to po stronie serwera
     * (akcja live + `url: mapPath`) i działało, ale kosztem wyścigu o pasek adresu: Turbo wpisuje
     * `/mail/42` przez `advance`, a kontroler live po każdej odpowiedzi robi `history.replaceState()`
     * i NADPISUJE ten wpis — `wstecz` gubił wtedy krok (pierwsze naciśnięcie nic nie robiło).
     * Podświetlenie to stan interfejsu, nie dane; serwer nie musi o nim wiedzieć.
     */
    #[LiveProp]
    public ?int $messageId = null;

    /**
     * Fraza z pola szukania.
     *
     * USTAWIANA przez `data-model="debounce(300)|query"` w szablonie komponentu — kontroler live
     * przypisuje ją wprost, bez akcji. Writable = input użytkownika, więc `searchPage()` eskejpuje
     * `%` i `_` po swojej stronie.
     */
    #[LiveProp(writable: true)]
    public string $query = '';

    /**
     * Numer strony.
     *
     * USTAWIANY akcjami `previousPage()` / `nextPage()`, nie modelem — paginacja to przyciski,
     * nie pole formularza. Z tego samego powodu nie jest `writable`. `searchPage()` i tak
     * przycina wartości spoza zakresu.
     */
    #[LiveProp]
    public int $page = 1;

    /**
     * Konta użytkownika do paska na wąskim ekranie — WYŁĄCZNIE do wyświetlenia.
     *
     * USTAWIANE Z ZEWNĄTRZ, przez atrybut `:accounts` w `mail/_list.html.twig` — czyli tylko przy
     * pełnym renderze strony. Akcje live go NIE odświeżają, i słusznie: zmiana konta to pełna
     * nawigacja Drive, więc lista i tak przyjeżdża wtedy na nowo z kontrolera.
     *
     * Zwykła właściwość, nie `LiveProp`: to nie jest stan, który komponent zmienia, a encji i tak
     * nie da się odesłać w żądaniu.
     *
     * @var list<MailAccount>
     */
    public array $accounts = [];

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MailAccountRepository $mailAccountRepository,
        private readonly Security $security,
    ) {
    }

    /**
     * Strona wyników dla bieżącego stanu — w szablonie dostępna jako `page`.
     *
     * Nazwa metody celowo nie brzmi jak getter: `getPage()` zostałoby wzięte za getter właściwości
     * `page` do hydratacji („missing its property-type"), a to jest wynik LICZONY z `LiveProp`,
     * nie stan do odsyłania w żądaniu.
     *
     * @return MessageListPage Wiadomości + dane paginacji
     */
    #[ExposeInTemplate('page')]
    public function fetchPage(): MessageListPage {
        return $this->messageRepository->searchPage(
            accountIds: $this->accessibleAccounts(),
            query:      $this->query === '' ? null : $this->query,
            page:       $this->page,
            perPage:    self::PER_PAGE,
        );
    }

    /**
     * Wybrane konto jako encja — do nagłówka kolumny i podświetlenia w pasku kont.
     *
     * Wyprowadzone z `$accountId`, a NIE przekazywane osobno z kontrolera: encja i identyfikator
     * niosłyby tę samą informację w dwóch postaciach, więc mogłyby się rozjechać.
     *
     * @return MailAccount|null Konto albo null przy widoku „wszystkie konta"
     */
    #[ExposeInTemplate('selectedAccount')]
    public function fetchSelectedAccount(): ?MailAccount {
        $user = $this->security->getUser();

        return $user instanceof User
            ? $this->mailAccountRepository->findOneForUser($user, (int) $this->accountId)
            : null;
    }

    /**
     * Poprzednia strona listy.
     *
     * Dolna granica jest tutaj, mimo że `searchPage()` też przycina: bez niej `page` schodziłoby
     * poniżej jedynki i licznik „strona X z Y" pokazywałby wartość, której nie ma.
     */
    #[LiveAction]
    public function previousPage(): void {
        $this->page = max(1, $this->page - 1);
    }

    /**
     * Następna strona listy; górną granicę przycina `searchPage()`.
     */
    #[LiveAction]
    public function nextPage(): void {
        ++$this->page;
    }

    /**
     * Konta, po których wolno szukać — ZAWSZE z repozytorium, nigdy z samego `$accountId`.
     *
     * Tu mieszka cała reguła dostępu komponentu, w jednym miejscu. Obie gałęzie pytają o
     * przypisania M2M `User ↔ MailAccount`, więc reguła nie ma tu własnego egzemplarza: to ten sam
     * `accessibleQueryBuilder()`, z którego czerpie `MailController`.
     *
     * Gałąź `null` jest ważniejsza, niż wygląda: przy widoku „wszystkie konta" nie ma czego
     * podpisywać, więc „wszystkie" MUSI znaczyć „wszystkie moje", a nie „wszystkie w bazie".
     *
     * @return list<int> ID kont, np. [67]; pusta lista = użytkownik bez przypisanych kont
     */
    private function accessibleAccounts(): array {
        $user = $this->security->getUser();

        // Trasa komponentu jest za `ROLE_USER` (`access_control: ^/`), ale sam komponent nie ma
        // `#[IsGranted]` — przy braku użytkownika oddajemy pustkę zamiast wywracać się na typie.
        if (! $user instanceof User) {
            return [];
        }

        // Wybrane konto przechodzi przez tę samą bramkę, co `?account=` w kontrolerze:
        // cudze albo nieistniejące ID daje null i wracamy do „wszystkie MOJE konta".
        $selected = $this->mailAccountRepository->findOneForUser($user, (int) $this->accountId);

        return $selected === null
            ? $this->mailAccountRepository->findIdsForUser($user)
            : [(int) $selected->getId()];
    }
}
