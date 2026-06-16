<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'Użytkownik z tym adresem e-mail już istnieje.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Zahashowane hasło (nigdy plaintext).
     */
    #[ORM\Column]
    private string $password;

    /**
     * Konta IMAP, do których użytkownik ma dostęp (many-to-many).
     * Strona odwrotna — właścicielem relacji jest MailAccount.
     *
     * @var Collection<int, MailAccount>
     */
    #[ORM\ManyToMany(targetEntity: MailAccount::class, mappedBy: 'users')]
    private Collection $mailAccounts;

    public function __construct()
    {
        $this->mailAccounts = new ArrayCollection();
    }

    /**
     * Zwraca identyfikator użytkownika (null przed pierwszym zapisem).
     *
     * @return int|null Identyfikator, np. 7
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Zwraca adres e-mail użytkownika.
     *
     * @return string Adres e-mail, np. "protechnologia@gmail.com"
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Ustawia adres e-mail użytkownika.
     *
     * @param string $email Adres e-mail, np. "protechnologia@gmail.com"
     *
     * @return $this
     */
    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Identyfikator użytkownika w systemie security (tu: e-mail).
     *
     * @return string Identyfikator, np. "protechnologia@gmail.com"
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Zwraca role użytkownika (zawsze z co najmniej ROLE_USER).
     *
     * @return list<string> Role, np. ["ROLE_ADMIN", "ROLE_USER"]
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // Każdy zalogowany użytkownik ma co najmniej ROLE_USER.
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * Ustawia role użytkownika (bez dorzucania ROLE_USER — to robi getter).
     *
     * @param list<string> $roles Role, np. ["ROLE_ADMIN"]
     *
     * @return $this
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * Zwraca zahashowane hasło (nigdy plaintext).
     *
     * @return string Hash hasła, np. "$2y$13$abcdef…" (bcrypt)
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Ustawia zahashowane hasło (hashowanie robi warstwa security, nie ta metoda).
     *
     * @param string $password Hash hasła, np. "$2y$13$abcdef…" (bcrypt)
     *
     * @return $this
     */
    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Czyści dane wrażliwe przechowywane tymczasowo na obiekcie.
     * Nie trzymamy plaintext hasła, więc nie ma czego czyścić.
     */
    public function eraseCredentials(): void
    {
    }

    /**
     * Zwraca konta IMAP, do których użytkownik ma dostęp.
     *
     * @return Collection<int, MailAccount> Kolekcja, np. [MailAccount("Poczta firmowa eGIE")]
     */
    public function getMailAccounts(): Collection
    {
        return $this->mailAccounts;
    }

    /**
     * Przyznaje użytkownikowi dostęp do konta (idempotentnie) i synchronizuje stronę właścicielską.
     *
     * @param MailAccount $mailAccount Konto, np. MailAccount("Poczta firmowa eGIE")
     *
     * @return $this
     */
    public function addMailAccount(MailAccount $mailAccount): static
    {
        if (!$this->mailAccounts->contains($mailAccount)) {
            $this->mailAccounts->add($mailAccount);
            // Synchronizacja strony właścicielskiej.
            $mailAccount->addUser($this);
        }

        return $this;
    }

    /**
     * Odbiera użytkownikowi dostęp do konta i synchronizuje stronę właścicielską.
     *
     * @param MailAccount $mailAccount Konto, np. MailAccount("Poczta firmowa eGIE")
     *
     * @return $this
     */
    public function removeMailAccount(MailAccount $mailAccount): static
    {
        if ($this->mailAccounts->removeElement($mailAccount)) {
            $mailAccount->removeUser($this);
        }

        return $this;
    }

    /**
     * Etykieta użytkownika w UI (EasyAdmin, listy wyboru relacji) — adres e-mail.
     *
     * @return string Adres e-mail, np. "protechnologia@gmail.com"
     */
    public function __toString(): string
    {
        return $this->email ?? '';
    }
}
