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
 * Podgląd zarchiwizowanej poczty dla użytkownika (etap 4.2).
 *
 * Warstwa HTTP nad `MessageRepository::searchPage()`: lista widzi WYŁĄCZNIE konta przypisane
 * zalogowanemu użytkownikowi (M2M `User ↔ MailAccount`), a detal dodatkowo przechodzi przez
 * `MessageVoter` — bez skrótu dla `ROLE_ADMIN`, patrz docblok Votera.
 *
 * Zawężenie robimy w DWÓCH miejscach celowo, nie przez przeoczenie: lista dostaje konta
 * użytkownika (`searchPage()` nie ma trybu „wszystkie konta"), a detal pyta Votera o konkretną
 * encję. Pierwsze decyduje, CO widać na liście; drugie broni adresu wpisanego z palca. Obie
 * ścieżki czerpią z tego samego źródła — `MailAccountRepository::findForUser()/findIdsForUser()`
 * — więc nie mogą się rozjechać.
 *
 * Widoki są tu tymczasowe i minimalne — trójpanelowy layout na Turbo Frames wchodzi w 4.3,
 * reaktywne szukanie/paginacja (Live Component) w 4.4, a render treści maila z `.eml`
 * w sandboksowanym iframie w 4.5. Tutaj chodzi o działający, autoryzowany szkielet.
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
     * Lista wiadomości ze wszystkich kont użytkownika.
     *
     * Numer strony jedzie query stringiem (`?page=`), nie segmentem ścieżki: to stan widoku
     * na tę samą kolekcję, a nie tożsamość zasobu (inaczej niż `id` w `/mail/{id}`). Ścieżka
     * dałaby dwa adresy tej samej treści (`/mail` i `/mail/page/1`) i rozjechałaby się z 4.4,
     * gdzie `page` i `query` wjeżdżają do adresu jako `LiveProp(url: true)` — czyli właśnie
     * do query stringa.
     *
     * `$requestedPage` to ŻYCZENIE użytkownika i traktujemy je jak każdy inny input: `getInt()`
     * zamienia śmieci na 0, a `searchPage()` przycina wartość do zakresu — stąd osobna nazwa
     * od `$page`, czyli strony FAKTYCZNIE zwróconej. Filtr frazą świadomie jeszcze nie istnieje:
     * wchodzi w 4.4 jako writable `LiveProp`.
     *
     * @param Request $request Żądanie HTTP (czytamy `page`)
     * @param User    $user    Zalogowany użytkownik (gwarantowany przez `IsGranted` na klasie)
     *
     * @return Response Wyrenderowana lista
     */
    #[Route('', name: 'app_mail_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response {
        $requestedPage = $request->query->getInt('page', 1);

        // Argumenty nazwane, bo `query: null` na drugiej pozycji nic by nie mówiło — a w 4.4
        // wchodzi tu fraza z komponentu i ma być widać, które miejsce zajmuje.
        $page = $this->messageRepository->searchPage(
            accountIds: $this->mailAccountRepository->findIdsForUser($user),
            query:      null,
            page:       $requestedPage,
            perPage:    self::PER_PAGE,
        );

        return $this->render('mail/index.html.twig', [
            'accounts' => $this->mailAccountRepository->findForUser($user),
            'page'     => $page,
        ]);
    }

    /**
     * Detal jednej wiadomości.
     *
     * Nieistniejące ID daje 404, cudze — 403 (`MessageVoter`). Świadomie NIE maskujemy 403 na
     * 404: to archiwum firmowe, nie usługa publiczna, a jasny komunikat „nie masz dostępu"
     * jest tu bardziej użyteczny niż ukrywanie faktu istnienia rekordu.
     *
     * @param int $id Identyfikator wiadomości, np. 42
     *
     * @return Response Wyrenderowany detal
     */
    #[Route('/{id}', name: 'app_mail_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(int $id): Response {
        $message = $this->messageRepository->find($id);
        if (! $message instanceof Message) {
            throw $this->createNotFoundException(sprintf('Nie ma wiadomości o ID %d.', $id));
        }

        $this->denyAccessUnlessGranted(MessageVoter::VIEW, $message);

        return $this->render('mail/show.html.twig', [
            'message' => $message
        ]);
    }

}
