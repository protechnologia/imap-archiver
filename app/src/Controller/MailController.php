<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MailAccountRepository;
use App\Repository\MessageRepository;
use App\Security\Voter\MessageVoter;
use App\Service\MailBodyReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Podgląd zarchiwizowanej poczty dla użytkownika: konta → lista → wiadomość (etapy 4.2 i 4.3a).
 *
 * Warstwa HTTP nad `MessageRepository::searchPage()`. Cała klasa to jedna akcja obsługująca dwie
 * trasy — uzasadnienie przy `mailbox()`, tam też podział „ścieżka vs query string".
 *
 * Zawężenie dostępu stoi w TRZECH miejscach i każde odpowiada za co innego:
 *  - lista kont pochodzi z `MailAccountRepository::findForUser()` — decyduje, co w ogóle widać;
 *  - żądane `?account=` przechodzi przez `findOneForUser()` — cudze ID daje null, nie cudzą pocztę;
 *  - podgląd konkretnej wiadomości pyta `MessageVoter` — broni adresu wpisanego z palca.
 * Wszystkie trzy czerpią z jednego źródła prawdy o przypisaniach (M2M `User ↔ MailAccount`), więc
 * nie mogą się rozjechać. `MessageVoter` nie ma skrótu dla `ROLE_ADMIN` — patrz jego docblok.
 *
 * Stan widoków: układ trzech regionów działa na pełnych przeładowaniach, bez JS (4.3a). Turbo
 * Frames wchodzą w 4.3c, reaktywne szukanie i paginacja (Live Component `MailList`) w 4.4,
 * a render treści maila z `.eml` w sandboksowanym iframie w 4.5 — dziś `_message.html.twig`
 * pokazuje tylko tekstowe ziarno z indeksu.
 */
#[Route('/mail')]
#[IsGranted('ROLE_USER')]
class MailController extends AbstractController {

    /**
     * __construct
     */
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MailAccountRepository $mailAccountRepository,
        private readonly MailBodyReader $mailBodyReader,
        #[Autowire(service: 'html_sanitizer.sanitizer.mail.body')]
        private readonly HtmlSanitizerInterface $mailSanitizer,
        /**
         * Profiler wstrzyknięty OPCJONALNIE (`@?` = null, gdy usługi nie ma — czyli na produkcji).
         * Potrzebny wyłącznie po to, żeby `body()` mogła się wypisać z toolbara; uzasadnienie tam.
         */
        #[Autowire(service: 'profiler')]
        private readonly ?Profiler $profiler = null,
    ) {
    }

    /**
     * Skrzynka: konta → lista → podgląd. Jedna akcja obsługuje DWIE trasy (etap 4.3a).
     *
     * `/mail` pokazuje trzy panele bez wybranej wiadomości, `/mail/{id}` te same trzy panele
     * z wypełnionym podglądem. To celowo JEDNA akcja, nie dwie: gdyby detal renderował osobną
     * stronę, wejście z zakładki albo z historii gubiłoby kontekst (konta i listę), a po włączeniu
     * ramek w 4.3c trzeba by i tak scalić oba widoki. Różnicę niesie sam `$id` — `null` na trasie
     * listy, bo Symfony nie przekaże parametru, którego w ścieżce nie ma.
     *
     * Podział adresowania: wiadomość jest w ŚCIEŻCE (tożsamość zasobu — nie-liczba nie jest
     * wiadomością, stąd `Requirement::DIGITS` i 404 z routingu), a wybór konta i numer strony
     * w QUERY STRINGU (stan widoku na tę samą kolekcję). Ta sama zasada obowiązuje w 4.4, gdzie
     * `LiveProp(url: true)` zapisuje stan komponentu również w query stringu.
     *
     * Zawężenie dostępu stoi w trzech miejscach i każde odpowiada za co innego: lista kont
     * pochodzi z `findForUser()`, żądane `?account=` przechodzi przez `findOneForUser()` (cudze
     * ID daje null, nie cudzą pocztę), a detal pyta `MessageVoter` (cudzy mail → 403, nieistniejący
     * → 404; świadomie nie maskujemy 403 na 404 — to archiwum firmowe, nie usługa publiczna).
     *
     * Parametry z query stringa czytamy na samym początku metody, żeby było widać całe wejście
     * od użytkownika w jednym miejscu. Przedrostek `requested*` jest istotny: to ŻYCZENIE, które
     * może zostać odrzucone lub przycięte — cudze `?account=` daje `null`, a `?page=999`
     * przytnie `searchPage()` do ostatniej strony.
     *
     * @param Request  $request Żądanie HTTP (czytamy `account` i `page`)
     * @param User     $user    Zalogowany użytkownik (gwarantowany przez `IsGranted` na klasie)
     * @param int|null $id      Identyfikator oglądanej wiadomości albo null na `/mail`, np. 42
     *
     * @return Response Wyrenderowana skrzynka
     */
    #[Route('',      name: 'app_mail_index', methods: ['GET'])]
    #[Route('/{id}', name: 'app_mail_show',  methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function mailbox(Request $request, #[CurrentUser] User $user, ?int $id = null): Response {
        $requestedAccountId = $request->query->getInt('account');
        // Pusty string traktujemy jak brak życzenia — `resolveVariant()` i tak przycina wartość
        // do wariantów faktycznie dostępnych, więc `?view=cokolwiek` nie jest w stanie nic zepsuć.
        $requestedView = $request->query->getString('view') ?: null;

        // Wiadomość pobieramy tylko na trasie `/mail/{id}`; nieistniejące ID → 404.
        $message = $id === null ? null : $this->messageRepository->find($id);
        if ($id !== null && ! $message instanceof Message) {
            throw $this->createNotFoundException(sprintf('Nie ma wiadomości o ID %d.', $id));
        }

        // Cudza wiadomość → 403. Reguły nie ma w kontrolerze — rozstrzyga ją `MessageVoter`.
        if ($message !== null) {
            $this->denyAccessUnlessGranted(MessageVoter::VIEW, $message);
        }

        // Które konto pokazuje środkowa lista — kolejność ważności:
        //   1. jawnie wybrane w adresie (`?account=67`), czyli kliknięcie w panelu kont;
        //   2. konto oglądanej wiadomości — przy wejściu prosto na `/mail/42` (zakładka, link
        //      z czatu, historia przeglądarki) w adresie nie ma konta, a lista ma pokazać
        //      skrzynkę, z której ten mail pochodzi, a nie wszystko wymieszane;
        //   3. gdy nie ma ani jednego, ani drugiego — `null`, czyli wszystkie konta użytkownika.
        $selectedAccount = $this->mailAccountRepository->findOneForUser($user, $requestedAccountId) ?? $message?->getAccount();

        // Treść czytamy z `.eml`, nie z indeksu (`MailBodyReader`), i to samo odczytanie odpowiada
        // na dwa pytania: co pokazać ORAZ które pozycje przełącznika tekst/HTML są w ogóle czynne.
        $body = $message === null ? null : $this->mailBodyReader->read($message);

        return $this->render('mail/index.html.twig', [
            'accounts'        => $this->mailAccountRepository->findForUser($user),
            'selectedAccount' => $selectedAccount,
            'message'         => $message,
            'body'            => $body,
            'view'            => $body?->resolveVariant($requestedView),
        ]);
    }

    /**
     * Treść HTML wiadomości jako OSOBNY dokument, do osadzenia w `<iframe sandbox>` (etap 4.5).
     *
     * DLACZEGO OSOBNA TRASA, a nie `srcdoc` w szablonie skrzynki: własny dokument ma własną
     * odpowiedź, a więc własne nagłówki. Bez tego nie da się nałożyć `Content-Security-Policy`
     * ograniczonego wyłącznie do treści maila — `srcdoc` dziedziczy kontekst strony rodzica.
     *
     * TYLKO HTML, i to nie jest przeoczenie. Wariant tekstowy renderuje się wprost w kolumnie
     * podglądu (`<pre>` w `_message.html.twig`), bo Twig go escapuje i żadna z tutejszych zapór
     * nie jest mu do niczego potrzebna. Wsadzenie go tutaj kosztowałoby drugie żądanie przy
     * KAŻDYM otwarciu maila (tekst jest wariantem domyślnym) i odebrałoby Ctrl+F — przeglądarki
     * nie przeszukują wnętrza iframe'ów, a w archiwum szukanie w treści to podstawowa czynność.
     * Stąd 404 dla wiadomości bez HTML-a: to pytanie o wariant, którego zasób nie ma.
     *
     * TRZY ZAPORY, KAŻDA PRZED CZYM INNYM, żadna nie zastępuje pozostałych:
     *  - `html_sanitizer` (config `html_sanitizer.yaml`) — USUWA groźne konstrukcje z treści,
     *    zanim ta opuści serwer: skrypty, `onerror`, `javascript:`, `<base>`, style inline;
     *  - `Content-Security-Policy` — zabrania dokumentowi ŻĄDAĆ czegokolwiek z sieci, więc piksel
     *    śledzący nie zadzwoni do nadawcy nawet, gdyby przecisnął się przez sanitizer;
     *  - `sandbox` na iframie (`_message.html.twig`) — odbiera dokumentowi UPRAWNIENIA: opaque
     *    origin (brak dostępu do sesji i DOM-u rodzica), brak nawigacji górnej ramki, brak formularzy.
     * Awarie tych warstw są niezależne: nagłówek może uciąć proxy, atrybut nakłada strona rodzica,
     * a sanitizer działa jeszcze zanim cokolwiek pojedzie po sieci.
     *
     * `style-src` puszczamy przez NONCE, nie przez `'unsafe-inline'`. Różnica jest istotna:
     * `'unsafe-inline'` przepuściłby także `<style>` nadawcy, a nonce wyłącznie nasz blok — losowej
     * wartości nikt z zewnątrz nie zna. Uwaga: obecność nonce'a UNIEWAŻNIA `'unsafe-inline'`
     * w tej samej dyrektywie, więc mieszanie ich „na wszelki wypadek" nie działa.
     * `base-uri` i `form-action` wypisujemy JAWNIE, bo jako jedyne z użytych tu dyrektyw
     * NIE dziedziczą z `default-src` — samo `'none'` by ich nie objęło.
     *
     * @param Message $message Wiadomość rozwiązana z `{id}`, np. Message #42
     *
     * @return Response Dokument HTML treści maila z kompletem nagłówków bezpieczeństwa
     */
    #[Route('/{id}/body', name: 'app_mail_body', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function body(Message $message): Response {
        // Ta sama reguła dostępu co w podglądzie. Trasa jest osobnym punktem wejścia, więc musi
        // pytać Votera SAMA — zabezpieczenie `mailbox()` nie rozciąga się na nią w żaden sposób.
        $this->denyAccessUnlessGranted(MessageVoter::VIEW, $message);

        $html = $this->mailBodyReader->read($message)->html;
        if ($html === null) {
            throw $this->createNotFoundException(sprintf('Wiadomość %d nie ma treści HTML.', $message->getId()));
        }

        // Toolbar profilera wstrzykuje swój HTML do KAŻDEJ odpowiedzi `text/html` — także do tej,
        // czyli do wnętrza izolowanego dokumentu z cudzą korespondencją. W dev widać go wtedy
        // w podglądzie maila, a jego handler CSP dopisuje sobie do naszego nagłówka `'unsafe-inline'`
        // i własne nonce'y, więc to, co mierzysz w przeglądarce, przestaje być tym, co pójdzie
        // na produkcję. Wyłączenie profilera dla tego żądania zdejmuje nagłówek `X-Debug-Token`,
        // a bez niego listener toolbara wychodzi bez wstrzyknięcia.
        $this->profiler?->disable();

        $nonce    = bin2hex(random_bytes(16));
        $response = $this->render('mail/body.html.twig', [
            'content' => $this->mailSanitizer->sanitize($html),
            'nonce'   => $nonce,
        ]);

        $response->headers->set('Content-Security-Policy', sprintf(
            "default-src 'none'; style-src 'nonce-%s'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
            $nonce,
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // Treść cudzej korespondencji nie ma prawa wylądować w cache'u pośrednika ani na dysku.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

}
