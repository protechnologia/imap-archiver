import { Controller } from '@hotwired/stimulus';

/*
 * Podświetlenie wiersza odpowiadającego otwartej wiadomości (etap 4.4).
 *
 * Po co osobny kontroler, skoro klasę ustawia już Twig: klik w wiadomość podmienia WYŁĄCZNIE
 * ramkę podglądu, więc lista nie jest przerysowywana i zostaje z podświetleniem sprzed kliknięcia.
 *
 * Źródłem prawdy jest ADRES, nie kliknięcie. To istotne: gdyby kontroler reagował na klik,
 * podświetlenie rozjeżdżałoby się z podglądem po użyciu `wstecz` — adres i ramka wracają
 * do poprzedniej wiadomości, a kliknięcia wtedy nie ma. Nasłuch `turbo:frame-load` łapie
 * KAŻDĄ zmianę podglądu jednakowo: klik, `wstecz`, `dalej`.
 *
 * Dlaczego w JS-ie, a nie po stronie serwera. Próbowaliśmy przestawiać to akcją Live Componentu
 * (`messageId` + `LiveProp(url: mapPath)`) i działało, ale kosztem wyścigu o pasek adresu: Turbo
 * wpisuje `/mail/42` przez `data-turbo-action="advance"`, a kontroler live po KAŻDEJ odpowiedzi
 * robi `history.replaceState()` i nadpisywał ten wpis — `wstecz` gubił wtedy krok. Podświetlenie
 * to stan interfejsu, nie dane; serwer nie musi o nim wiedzieć.
 *
 * Cena, którą płacimy świadomie: reguła „aktywny wiersz" żyje w dwóch miejscach — Twig ustawia ją
 * przy renderze strony (deep link, F5, zmiana konta), ten kontroler przy nawigacji w obrębie listy.
 * Stąd klasy w stałych, żeby zmiana wyglądu wymagała poprawki w dwóch miejscach, a nie w pięciu.
 */
export default class extends Controller {
    static targets = ['row'];

    /*
     * Muszą być zgodne z klasami w `templates/components/MailList.html.twig` — Twig ustawia je
     * przy renderze strony, ten kontroler przy nawigacji w obrębie listy.
     *
     * Płynność daje `transition-colors` na wierszu (w szablonie), a nie te klasy: przełączamy
     * KOLOR obramowania, nie jego grubość, bo `border-l-2` jest na każdym wierszu i tylko
     * przezroczyste na nieaktywnych. Inaczej pojawienie się paska przesuwałoby treść.
     */
    static ACTIVE = ['border-l-blue-600', 'bg-blue-50'];
    static INACTIVE = ['border-l-transparent', 'hover:bg-slate-50'];

    connect() {
        // Zdarzenie leci na `document`, bo ramka podglądu jest POZA tym kontrolerem (siedzi
        // w sąsiedniej kolumnie) — nasłuch na własnym elemencie nigdy by go nie zobaczył.
        this.onFrameLoad = () => this.syncWithUrl();
        document.addEventListener('turbo:frame-load', this.onFrameLoad);
    }

    disconnect() {
        document.removeEventListener('turbo:frame-load', this.onFrameLoad);
    }

    /**
     * Przenosi podświetlenie na wiersz wskazany przez adres.
     *
     * Adres bez identyfikatora (`/mail`) znaczy „nic nie jest otwarte" — wtedy podświetlenie
     * znika, zamiast zostać na ostatnio oglądanej wiadomości.
     */
    syncWithUrl() {
        const match = window.location.pathname.match(/\/mail\/(\d+)/);
        const openedId = match === null ? null : match[1];

        this.rowTargets.forEach((row) => {
            const isActive = row.dataset.activeRowIdParam === openedId;

            this.constructor.ACTIVE.forEach((name) => row.classList.toggle(name, isActive));
            this.constructor.INACTIVE.forEach((name) => row.classList.toggle(name, !isActive));
        });
    }
}
