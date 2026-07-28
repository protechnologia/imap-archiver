<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MailAccountRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Kto może OBEJRZEĆ wiadomość w podglądzie użytkownika (etap 4.2).
 *
 * Jedyne kryterium to przypisanie M2M `User ↔ MailAccount`: widzisz wiadomość wtedy i tylko
 * wtedy, gdy masz dostęp do konta, z którego pochodzi. Samą listę kont bierzemy z
 * `MailAccountRepository::findIdsForUser()` — z tego samego źródła, z którego składa się lista
 * wiadomości, żeby jedno nie mogło rozjechać się z drugim.
 *
 * ŚWIADOMIE BEZ SKRÓTU DLA `ROLE_ADMIN` — admin na froncie jest zwykłym czytelnikiem SWOJEJ
 * poczty i dostaje 403 na cudzej. Rola opisuje funkcję administracyjną (zakładanie kont,
 * import, w etapie 6 kasowanie), nie uprawnienie do cudzej korespondencji. Panel EasyAdmin
 * tego nie obchodzi: `MessageCrudController` pokazuje wyłącznie metadane indeksu, treści
 * (`body`, `.eml`) tam nie ma. Gdy admin naprawdę musi przeczytać cudzą skrzynkę, przypisuje
 * sobie konto w panelu — jawny wpis w `mail_account_user` zamiast niewidocznego obejścia w kodzie.
 * Uwaga na etap 6: uprawnienie do KASOWANIA to osobna sprawa (`ROLE_ADMIN`), nie rozszerzenie `VIEW`.
 */
class MessageVoter extends Voter {
    /** Podgląd wiadomości (lista i detal). */
    public const VIEW = 'VIEW';

    /**
     * __construct
     */
    public function __construct(
        private readonly MailAccountRepository $mailAccountRepository,
    ) {
    }

    /**
     * Czy ten Voter w ogóle wypowiada się o tej parze (atrybut, obiekt).
     *
     * @param string $attribute Sprawdzane uprawnienie, np. "VIEW"
     * @param mixed  $subject   Obiekt, którego dotyczy pytanie, np. Message #42
     *
     * @return bool true, gdy pytanie dotyczy podglądu wiadomości
     */
    protected function supports(string $attribute, mixed $subject): bool {
        return $attribute === self::VIEW && $subject instanceof Message;
    }

    /**
     * Właściwa decyzja: czy zalogowany użytkownik ma dostęp do konta tej wiadomości.
     *
     * Porównujemy po ID, a nie po tożsamości obiektów — ta opiera się na identity map Doctrine
     * i przestaje być pewna po `EntityManager::clear()` w długo żyjącym workerze (etap 5).
     *
     * @param string         $attribute Uprawnienie (tu zawsze "VIEW")
     * @param mixed          $subject   Wiadomość, np. Message #42
     * @param TokenInterface $token     Token zalogowanego użytkownika (może być anonimowy)
     *
     * @return bool true, gdy wolno obejrzeć wiadomość
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Message) {
            return false;
        }

        $accountId = $subject->getAccount()?->getId();

        return $accountId !== null
            && in_array($accountId, $this->mailAccountRepository->findIdsForUser($user), true);
    }
}
