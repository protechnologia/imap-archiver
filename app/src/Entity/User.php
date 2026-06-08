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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Identyfikator użytkownika w systemie security (tu: e-mail).
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // Każdy zalogowany użytkownik ma co najmniej ROLE_USER.
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

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
     * @return Collection<int, MailAccount>
     */
    public function getMailAccounts(): Collection
    {
        return $this->mailAccounts;
    }

    public function addMailAccount(MailAccount $mailAccount): static
    {
        if (!$this->mailAccounts->contains($mailAccount)) {
            $this->mailAccounts->add($mailAccount);
            // Synchronizacja strony właścicielskiej.
            $mailAccount->addUser($this);
        }

        return $this;
    }

    public function removeMailAccount(MailAccount $mailAccount): static
    {
        if ($this->mailAccounts->removeElement($mailAccount)) {
            $mailAccount->removeUser($this);
        }

        return $this;
    }
}
