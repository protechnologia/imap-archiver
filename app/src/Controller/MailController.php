<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MailAccountRepository;
use App\Repository\MessageRepository;
use App\Security\Voter\MessageVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    /** Rozmiar strony listy. W 4.4 przejmuje go `LiveProp` komponentu `MailList`. */
    private const PER_PAGE = 50;

    /**
     * __construct
     */
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MailAccountRepository $mailAccountRepository,
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
        $requestedPage      = $request->query->getInt('page', 1);

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

        // Brak wybranego konta = lista ze WSZYSTKICH kont użytkownika, a nie z żadnego.
        $accountIds = $selectedAccount === null ? $this->mailAccountRepository->findIdsForUser($user) : [(int) $selectedAccount->getId()];

        $page = $this->messageRepository->searchPage(
            accountIds: $accountIds,
            query:      null,
            page:       $requestedPage,
            perPage:    self::PER_PAGE,
        );

        return $this->render('mail/index.html.twig', [
            'accounts'        => $this->mailAccountRepository->findForUser($user),
            'selectedAccount' => $selectedAccount,
            'page'            => $page,
            'message'         => $message,
        ]);
    }

}
