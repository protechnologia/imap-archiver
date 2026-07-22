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
 * Etap 1.3 — sam model. Etap 1.4 — poświadczenie (hasło / refresh_token) jako pole
 * `secret`, szyfrowane at-rest przez typ `encrypted_string`, NIGDY w plaintext.
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
     * Poświadczenie do IMAP zależne od `authType`: hasło (Password) lub refresh_token
     * (Xoauth2). Szyfrowane at-rest typem `encrypted_string` — w bazie leży szyfrogram,
     * w PHP wartość jawna. NIGDY nie wystawiać na liście/w logach.
     */
    #[ORM\Column(type: 'encrypted_string', nullable: true)]
    private ?string $secret = null;

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

    /**
     * Zwraca identyfikator konta (null przed pierwszym zapisem).
     *
     * @return int|null Identyfikator, np. 67
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Zwraca przyjazną nazwę konta wyświetlaną w panelu.
     *
     * @return string Etykieta, np. "Poczta firmowa"
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Ustawia przyjazną nazwę konta.
     *
     * @param string $label Etykieta, np. "Poczta firmowa"
     *
     * @return $this
     */
    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Zwraca host serwera IMAP.
     *
     * @return string Adres serwera, np. "imap.example.com"
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Ustawia host serwera IMAP.
     *
     * @param string $host Adres serwera, np. "imap.example.com"
     *
     * @return $this
     */
    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    /**
     * Zwraca port IMAP (z niego wnioskujemy szyfrowanie).
     *
     * @return int Port, np. 993 (implicit SSL) albo 143 (STARTTLS)
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Ustawia port IMAP.
     *
     * @param int $port Port, np. 993 (implicit SSL) albo 143 (STARTTLS)
     *
     * @return $this
     */
    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    /**
     * Zwraca login do skrzynki (zwykle pełny adres e-mail).
     *
     * @return string Login, np. "poczta@example.com"
     */
    public function getImapLogin(): string
    {
        return $this->imapLogin;
    }

    /**
     * Ustawia login do skrzynki.
     *
     * @param string $imapLogin Login, np. "poczta@example.com"
     *
     * @return $this
     */
    public function setImapLogin(string $imapLogin): static
    {
        $this->imapLogin = $imapLogin;

        return $this;
    }

    /**
     * Zwraca folder źródłowy importu.
     *
     * @return string Nazwa folderu, np. "INBOX" (albo "INBOX.Archiwum")
     */
    public function getFolder(): string
    {
        return $this->folder;
    }

    /**
     * Ustawia folder źródłowy importu.
     *
     * @param string $folder Nazwa folderu, np. "INBOX" (albo "INBOX.Archiwum")
     *
     * @return $this
     */
    public function setFolder(string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    /**
     * Zwraca typ uwierzytelniania konta.
     *
     * @return AuthType Typ, np. AuthType::Password
     */
    public function getAuthType(): AuthType
    {
        return $this->authType;
    }

    /**
     * Ustawia typ uwierzytelniania konta.
     *
     * @param AuthType $authType Typ, np. AuthType::Password
     *
     * @return $this
     */
    public function setAuthType(AuthType $authType): static
    {
        $this->authType = $authType;

        return $this;
    }

    /**
     * Zwraca poświadczenie IMAP w postaci jawnej (deszyfrowane przez typ `encrypted_string`).
     *
     * @return string|null Hasło/refresh_token, np. "TajneHasło123" (w bazie leży szyfrogram)
     */
    public function getSecret(): ?string
    {
        return $this->secret;
    }

    /**
     * Ustawia poświadczenie IMAP w postaci jawnej (szyfrowane przy zapisie do bazy).
     *
     * @param string|null $secret Hasło/refresh_token, np. "TajneHasło123"
     *
     * @return $this
     */
    public function setSecret(?string $secret): static
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * Sprawdza, czy poświadczenie jest ustawione — bez ujawniania jego wartości.
     *
     * @return bool Czy ustawione, np. true
     */
    public function hasSecret(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * Zwraca użytkowników z dostępem do tego konta.
     *
     * @return Collection<int, User> Kolekcja, np. [User(protechnologia@gmail.com), User(admin@example.com)]
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /**
     * Przyznaje użytkownikowi dostęp do tego konta (idempotentnie).
     *
     * @param User $user Użytkownik, np. User(protechnologia@gmail.com)
     *
     * @return $this
     */
    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }

        return $this;
    }

    /**
     * Odbiera użytkownikowi dostęp do tego konta.
     *
     * @param User $user Użytkownik, np. User(protechnologia@gmail.com)
     *
     * @return $this
     */
    public function removeUser(User $user): static
    {
        $this->users->removeElement($user);

        return $this;
    }

    /**
     * Etykieta konta na listach w UI (EasyAdmin, listy wyboru relacji).
     *
     * @return string Etykieta, np. "Poczta firmowa"
     */
    public function __toString(): string
    {
        return $this->label ?? '';
    }
}
