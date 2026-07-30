<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MailAccountRepository;
use App\Security\Voter\MessageVoter;
use App\Tests\Fixtures\EntityFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Reguła dostępu do wiadomości — jednostkowo, bez bazy i bez HTTP (etap 4.2).
 *
 * Repozytorium jest podstawione, więc test mówi wyłącznie o REGULE: „widzisz wiadomość, gdy
 * masz przypisane jej konto". To, czy Voter jest w ogóle podpięty do kontrolera, sprawdza
 * `MailControllerTest` — tego z podstawionym repozytorium zobaczyć się nie da.
 *
 * Najważniejszy przypadek to `testNieprzypisanyAdminNieWidziCudzejWiadomosci()`: utrwala
 * decyzję z etapu 4.2, że `ROLE_ADMIN` NIE jest skrótem do cudzej korespondencji.
 */
class MessageVoterTest extends TestCase {
    private const ACCOUNT_ID = 67;
    private const OTHER_ACCOUNT_ID = 68;

    public function testPrzypisanyUzytkownikWidziWiadomosc(): void {
        $user = EntityFactory::user('user@example.com');

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($user, [self::ACCOUNT_ID], $this->messageFromAccount(self::ACCOUNT_ID)),
        );
    }

    public function testNieprzypisanyUzytkownikNieWidziWiadomosci(): void {
        $user = EntityFactory::user('outsider@example.com');

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($user, [self::OTHER_ACCOUNT_ID], $this->messageFromAccount(self::ACCOUNT_ID)),
        );
    }

    /**
     * Sedno decyzji z etapu 4.2 — rola administracyjna nie daje wglądu w cudzą pocztę.
     * Gdyby ktoś dołożył w Voterze skrót `if ($user->isAdmin()) return true`, ten test padnie.
     */
    public function testNieprzypisanyAdminNieWidziCudzejWiadomosci(): void {
        $admin = EntityFactory::user('admin@example.com', ['ROLE_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($admin, [], $this->messageFromAccount(self::ACCOUNT_ID)),
        );
    }

    public function testPrzypisanyAdminWidziWiadomosc(): void {
        $admin = EntityFactory::user('admin@example.com', ['ROLE_ADMIN']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($admin, [self::ACCOUNT_ID], $this->messageFromAccount(self::ACCOUNT_ID)),
        );
    }

    /**
     * Wiadomość bez konta nie powstaje w imporcie (FK `NOT NULL`), ale Voter i tak nie ma prawa
     * jej wpuścić — brak konta to brak podstawy do zgody, nie „wszystko wolno".
     */
    public function testWiadomoscBezKontaJestOdrzucana(): void {
        $user = EntityFactory::user('user@example.com');

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($user, [self::ACCOUNT_ID], new Message()),
        );
    }

    public function testNiezalogowanyNieWidziNiczego(): void {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $voter = new MessageVoter($this->repositoryReturning([self::ACCOUNT_ID]));

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, $this->messageFromAccount(self::ACCOUNT_ID), [MessageVoter::VIEW]),
        );
    }

    /**
     * Voter ma się nie wypowiadać poza swoim zakresem — inaczej blokowałby decyzje innych
     * Voterów (np. przyszłego `DELETE` z etapu 6).
     */
    public function testNieWypowiadaSieOInnymAtrybucieAniInnymObiekcie(): void {
        $user  = EntityFactory::user('user@example.com');
        $voter = new MessageVoter($this->repositoryReturning([self::ACCOUNT_ID]));
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, $this->messageFromAccount(self::ACCOUNT_ID), ['DELETE']),
            'Atrybut spoza zakresu Votera',
        );
        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($token, new MailAccount(), [MessageVoter::VIEW]),
            'Obiekt spoza zakresu Votera',
        );
    }

    /**
     * Puszcza głosowanie dla użytkownika mającego dostęp do podanych kont.
     *
     * @param User      $user               Głosujący użytkownik
     * @param list<int> $accessibleAccounts ID kont zwracane przez podstawione repozytorium, np. [67]
     * @param Message   $message            Wiadomość, o którą pytamy
     *
     * @return int Stała `VoterInterface::ACCESS_*`
     */
    private function vote(User $user, array $accessibleAccounts, Message $message): int {
        $voter = new MessageVoter($this->repositoryReturning($accessibleAccounts));
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $voter->vote($token, $message, [MessageVoter::VIEW]);
    }

    /**
     * Repozytorium podstawione tak, by oddawało z góry ustaloną listę kont użytkownika.
     *
     * @param list<int> $accountIds ID kont, np. [67, 68]
     *
     * @return MailAccountRepository Atrapa
     */
    private function repositoryReturning(array $accountIds): MailAccountRepository {
        $repository = $this->createStub(MailAccountRepository::class);
        $repository->method('findIdsForUser')->willReturn($accountIds);

        return $repository;
    }

    /**
     * Wiadomość przypisana do konta o podanym ID (encje bez bazy, ID wstawione refleksją).
     *
     * @param int $accountId ID konta, np. 67
     *
     * @return Message Wiadomość gotowa do głosowania
     */
    private function messageFromAccount(int $accountId): Message {
        $account = EntityFactory::account();
        EntityFactory::withId($account, $accountId);

        return EntityFactory::message($account);
    }
}
