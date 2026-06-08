<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthType;
use App\Repository\MailAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Konto IMAP, z którego archiwizujemy pocztę.
 *
 * Etap 1.3 — sam model. Poświadczenia (hasło / refresh_token) dochodzą zaszyfrowane
 * w etapie 1.4, NIGDY w plaintext.
 */
#[ORM\Entity(repositoryClass: MailAccountRepository::class)]
class MailAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Przyjazna nazwa konta na liście w panelu. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $host;

    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\Range(min: 1, max: 65535)]
    private int $port = 993;

    /** Login do skrzynki (zwykle pełny adres e-mail). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $imapLogin;

    /** Folder źródłowy importu. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $folder = 'INBOX';

    #[ORM\Column(enumType: AuthType::class)]
    private AuthType $authType = AuthType::Password;

    /**
     * Użytkownicy z dostępem do podglądu tego konta (many-to-many).
     * Strona właścicielska relacji.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'mailAccounts')]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getImapLogin(): string
    {
        return $this->imapLogin;
    }

    public function setImapLogin(string $imapLogin): static
    {
        $this->imapLogin = $imapLogin;

        return $this;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function setFolder(string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getAuthType(): AuthType
    {
        return $this->authType;
    }

    public function setAuthType(AuthType $authType): static
    {
        $this->authType = $authType;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        $this->users->removeElement($user);

        return $this;
    }

    public function __toString(): string
    {
        return $this->label ?? '';
    }
}
