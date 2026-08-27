import { Controller } from '@hotwired/stimulus';

/*
 * Podświetlenie wiersza odpowiadającego otwartej wiadomości (etap 4.4).
 *
 * JEDYNE ŹRÓDŁO tej klasy. Szablon renderuje wiersze zawsze jako nieaktywne, komponent nie zna ID
 * otwartej wiadomości — całą regułę „który wiersz jest aktywny" trzyma ten kontroler.
 *
 * Źródłem prawdy jest ADRES, nie kliknięcie. To istotne z dwóch powodów. Po pierwsze, gdyby
 * kontroler reagował na klik, podświetlenie rozjeżdżałoby się z podglądem po użyciu `wstecz` —
 * adres i ramka wracają do poprzedniej wiadomości, a kliknięcia wtedy nie ma. Po drugie, adres
 * jest jedyną rzeczą wspólną dla wszystkich dróg dojścia: kliku, `wstecz`, `dalej`, deep linku
 * i F5.
 *
 * Dlaczego w JS-ie, a nie po stronie serwera. Próbowaliśmy przestawiać to akcją Live Componentu
 * (`messageId` + `LiveProp(url: mapPath)`) i działało, ale kosztem wyścigu o pasek adresu: Turbo
 * wpisuje `/mail/42` przez `data-turbo-action="advance"`, a kontroler live po KAŻDEJ odpowiedzi
 * robi `history.replaceState()` i nadpisywał ten wpis — `wstecz` gubił wtedy krok. Podświetlenie
 * to stan interfejsu, nie dane; serwer nie musi o nim wiedzieć.
 *
 * DWA WEJŚCIA, bo są dwie różne sytuacje:
 *   • `turbo:frame-load` — zmienił się ADRES, wiersze zostały te same (klik, `wstecz`, `dalej`);
 *   • `rowTargetConnected()` — zmieniły się WIERSZE, adres został ten sam (szukanie, paginacja).
 * Drugie działa dzięki `data-skip-morph` na `<ul>`: lista jest wymieniana hurtem, więc każdy
 * wiersz pojawia się jako nowy element i sam pyta o stan. Bez tego atrybutu wiersze byłyby
 * morfowane w miejscu, target nie zgłaszałby się ponownie, a Live Component odtwarzałby klasę
 * na wierszu z tego samego MIEJSCA — czyli na innej wiadomości.
 */
export default class extends Controller {
    static targets = ['row'];

    /*
     * Muszą być zgodne z klasą wiersza w `templates/components/MailList.html.twig` — szablon
     * ustawia stan neutralny, ten kontroler przełącza go na aktywny i z powrotem.
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
     * Nowy wiersz pojawił się w DOM — od razu dostaje stan wynikający z adresu.
     *
     * Stimulus woła to także dla wierszy obecnych w chwili podłączenia kontrolera, więc pokrywa
     * pierwszy render strony (deep link, F5) i nie trzeba osobnego przebiegu w `connect()`.
     *
     * @param {HTMLElement} row Element `<li>` wiersza
     */
    rowTargetConnected(row) {
        this.paint(row, this.openedId());
    }

    /**
     * Przenosi podświetlenie na wiersz wskazany przez adres.
     *
     * Adres bez identyfikatora (`/mail`) znaczy „nic nie jest otwarte" — wtedy podświetlenie
     * znika, zamiast zostać na ostatnio oglądanej wiadomości.
     */
    syncWithUrl() {
        const openedId = this.openedId();

        this.rowTargets.forEach((row) => this.paint(row, openedId));
    }

    /**
     * @param {HTMLElement} row Element `<li>` wiersza
     * @param {?string} openedId ID otwartej wiadomości albo null, np. "42"
     */
    paint(row, openedId) {
        const isActive = row.dataset.messageId === openedId;

        this.constructor.ACTIVE.forEach((name) => row.classList.toggle(name, isActive));
        this.constructor.INACTIVE.forEach((name) => row.classList.toggle(name, !isActive));
    }

    /**
     * @return {?string} ID wiadomości z adresu, np. "42"; null gdy żadna nie jest otwarta
     */
    openedId() {
        const match = window.location.pathname.match(/\/mail\/(\d+)/);

        return match === null ? null : match[1];
    }
}
