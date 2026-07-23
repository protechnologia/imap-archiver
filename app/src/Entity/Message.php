<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Zaindeksowana wiadomość. Etap 3.
 *
 * To jest INDEKS, nie źródło prawdy — źródłem jest surowy plik `.eml` (`archivePath`,
 * zaadresowany przez `sha256`). Bazę da się odbudować z archiwum, nigdy odwrotnie.
 * Pola tekstowe (`subject`, `from*`, `body`) to sparsowana treść do podglądu i szukania;
 * w razie wątpliwości autorytatywne jest to, co leży w `.eml`.
 *
 * Idempotencja: `UNIQUE(account_id, sha256)`. Tożsamością treści jest `sha256` surowego
 * `.eml` (zawsze obecny), NIE `messageId` (bywa NULL → w Postgresie nie nadawałby się na
 * klucz unikalny). `messageId` trzymamy nullable + zindeksowane, do cross-referencji.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\UniqueConstraint(name: 'uniq_message_account_sha256', columns: ['account_id', 'sha256'])]
#[ORM\Index(name: 'idx_message_message_id', columns: ['message_id'])]
#[ORM\Index(name: 'idx_message_account_date', columns: ['account_id', 'sent_at'])]
class Message {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Konto, z którego pochodzi wiadomość. Strona właścicielska relacji (FK account_id). */
    #[ORM\ManyToOne(targetEntity: MailAccount::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?MailAccount $account = null;

    /** Folder źródłowy, w którym wiadomość znaleziono na serwerze. */
    #[ORM\Column(length: 255)]
    private string $folder = 'INBOX';

    /** Nagłówek `Message-ID` (bez nawiasów). Bywa NULL — patrz docblok klasy. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromEmail = null;

    /** Data z nagłówka `Date`. Kolumna `sent_at` (unikamy słowa kluczowego `date`). */
    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date = null;

    /** Rozmiar surowego `.eml` w bajtach. */
    #[ORM\Column]
    private int $size = 0;

    /** SHA-256 (hex) surowych bajtów `.eml`. Tożsamość treści i adres pliku w archiwum. */
    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column]
    private bool $hasAttachments = false;

    /**
     * Zweryfikowane = jest w DB + plik `.eml` istnieje + przeliczony `sha256` się zgadza.
     * Warunek konieczny bezpiecznego usuwania z serwera (etap 6). Ustawiane w 3.3.
     */
    #[ORM\Column]
    private bool $verified = false;

    /** Ścieżka WZGLĘDNA do `.eml` w archiwum (np. `67/2026/06/<sha256>.eml`). */
    #[ORM\Column(length: 255)]
    private string $archivePath;

    /** Sparsowana treść do podglądu/szukania (text lub render z `.eml`). Populowane w 3.3. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    /** UID wiadomości w folderze IMAP w chwili importu (pomocniczo; UID-y bywają reset). */
    #[ORM\Column(nullable: true)]
    private ?int $imapUid = null;

    /**
     * Metadane załączników (bajty zostają w `.eml`, nie duplikujemy na dysk).
     *
     * @var Collection<int, Attachment>
     */
    #[ORM\OneToMany(targetEntity: Attachment::class, mappedBy: 'message', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attachments;

    public function __construct() {
        $this->attachments = new ArrayCollection();
    }

    /**
     * Zwraca identyfikator wiadomości (null przed pierwszym zapisem).
     *
     * @return int|null Identyfikator, np. 42
     */
    public function getId(): ?int {
        return $this->id;
    }

    /**
     * Zwraca konto, z którego pochodzi wiadomość.
     *
     * @return MailAccount|null Konto, np. MailAccount #12 (poczta@example.com)
     */
    public function getAccount(): ?MailAccount {
        return $this->account;
    }

    /**
     * Ustawia konto, z którego pochodzi wiadomość.
     *
     * @param MailAccount|null $account Konto, np. MailAccount #12 (poczta@example.com)
     *
     * @return $this
     */
    public function setAccount(?MailAccount $account): static {
        $this->account = $account;

        return $this;
    }

    /**
     * Zwraca folder źródłowy, w którym wiadomość znaleziono.
     *
     * @return string Nazwa folderu, np. "INBOX"
     */
    public function getFolder(): string {
        return $this->folder;
    }

    /**
     * Ustawia folder źródłowy wiadomości.
     *
     * @param string $folder Nazwa folderu, np. "INBOX"
     *
     * @return $this
     */
    public function setFolder(string $folder): static {
        $this->folder = $folder;

        return $this;
    }

    /**
     * Zwraca nagłówek `Message-ID` (bez nawiasów) lub null.
     *
     * @return string|null Message-ID, np. "84df8acb-6be1-41a6-8e11-a7151eb385fa@example.com"
     */
    public function getMessageId(): ?string {
        return $this->messageId;
    }

    /**
     * Ustawia nagłówek `Message-ID` (lub null, gdy mail go nie miał).
     *
     * @param string|null $messageId Message-ID, np. "84df8acb-6be1-41a6-8e11-a7151eb385fa@example.com"
     *
     * @return $this
     */
    public function setMessageId(?string $messageId): static {
        $this->messageId = $messageId;

        return $this;
    }

    /**
     * Zwraca temat wiadomości (sparsowany do podglądu/szukania).
     *
     * @return string|null Temat, np. "Test z załącznikiem"
     */
    public function getSubject(): ?string {
        return $this->subject;
    }

    /**
     * Ustawia temat wiadomości.
     *
     * @param string|null $subject Temat, np. "Test z załącznikiem"
     *
     * @return $this
     */
    public function setSubject(?string $subject): static {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Zwraca nazwę nadawcy z nagłówka `From`.
     *
     * @return string|null Nazwa, np. "Jan Kowalski"
     */
    public function getFromName(): ?string {
        return $this->fromName;
    }

    /**
     * Ustawia nazwę nadawcy.
     *
     * @param string|null $fromName Nazwa, np. "Jan Kowalski"
     *
     * @return $this
     */
    public function setFromName(?string $fromName): static {
        $this->fromName = $fromName;

        return $this;
    }

    /**
     * Zwraca adres e-mail nadawcy z nagłówka `From`.
     *
     * @return string|null Adres, np. "jan.kowalski@example.com"
     */
    public function getFromEmail(): ?string {
        return $this->fromEmail;
    }

    /**
     * Ustawia adres e-mail nadawcy.
     *
     * @param string|null $fromEmail Adres, np. "jan.kowalski@example.com"
     *
     * @return $this
     */
    public function setFromEmail(?string $fromEmail): static {
        $this->fromEmail = $fromEmail;

        return $this;
    }

    /**
     * Zwraca datę z nagłówka `Date`.
     *
     * @return \DateTimeImmutable|null Data, np. new \DateTimeImmutable('2026-06-16 07:58:58')
     */
    public function getDate(): ?\DateTimeImmutable {
        return $this->date;
    }

    /**
     * Ustawia datę z nagłówka `Date`.
     *
     * @param \DateTimeImmutable|null $date Data, np. new \DateTimeImmutable('2026-06-16 07:58:58')
     *
     * @return $this
     */
    public function setDate(?\DateTimeImmutable $date): static {
        $this->date = $date;

        return $this;
    }

    /**
     * Zwraca rozmiar surowego `.eml` w bajtach.
     *
     * @return int Rozmiar w bajtach, np. 49189
     */
    public function getSize(): int {
        return $this->size;
    }

    /**
     * Ustawia rozmiar surowego `.eml` w bajtach.
     *
     * @param int $size Rozmiar w bajtach, np. 49189
     *
     * @return $this
     */
    public function setSize(int $size): static {
        $this->size = $size;

        return $this;
    }

    /**
     * Zwraca SHA-256 (hex) surowych bajtów `.eml` — tożsamość treści.
     *
     * @return string SHA-256, np. "6f041317c753…e1" (64 znaki hex)
     */
    public function getSha256(): string {
        return $this->sha256;
    }

    /**
     * Ustawia SHA-256 (hex) surowych bajtów `.eml`.
     *
     * @param string $sha256 SHA-256, np. "6f041317c753…e1" (64 znaki hex)
     *
     * @return $this
     */
    public function setSha256(string $sha256): static {
        $this->sha256 = $sha256;

        return $this;
    }

    /**
     * Sprawdza, czy wiadomość ma załączniki.
     *
     * @return bool Czy ma załączniki, np. true
     */
    public function hasAttachments(): bool {
        return $this->hasAttachments;
    }

    /**
     * Ustawia flagę obecności załączników.
     *
     * @param bool $hasAttachments Czy ma załączniki, np. true
     *
     * @return $this
     */
    public function setHasAttachments(bool $hasAttachments): static {
        $this->hasAttachments = $hasAttachments;

        return $this;
    }

    /**
     * Sprawdza, czy wiadomość jest zweryfikowana (DB + plik + zgodny checksum).
     *
     * @return bool Czy zweryfikowana, np. true
     */
    public function isVerified(): bool {
        return $this->verified;
    }

    /**
     * Ustawia flagę weryfikacji (warunek bezpiecznego usuwania z serwera — etap 6).
     *
     * @param bool $verified Czy zweryfikowana, np. true
     *
     * @return $this
     */
    public function setVerified(bool $verified): static {
        $this->verified = $verified;

        return $this;
    }

    /**
     * Zwraca względną ścieżkę do `.eml` w archiwum.
     *
     * @return string Ścieżka względna, np. "67/2026/06/6f041317c753….eml"
     */
    public function getArchivePath(): string {
        return $this->archivePath;
    }

    /**
     * Ustawia względną ścieżkę do `.eml` w archiwum.
     *
     * @param string $archivePath Ścieżka względna, np. "67/2026/06/6f041317c753….eml"
     *
     * @return $this
     */
    public function setArchivePath(string $archivePath): static {
        $this->archivePath = $archivePath;

        return $this;
    }

    /**
     * Zwraca sparsowaną treść do podglądu/szukania (lub null).
     *
     * @return string|null Treść, np. "Cześć, w załączniku przesyłam fakturę…"
     */
    public function getBody(): ?string {
        return $this->body;
    }

    /**
     * Ustawia sparsowaną treść do podglądu/szukania.
     *
     * @param string|null $body Treść, np. "Cześć, w załączniku przesyłam fakturę…"
     *
     * @return $this
     */
    public function setBody(?string $body): static {
        $this->body = $body;

        return $this;
    }

    /**
     * Zwraca UID wiadomości w folderze IMAP z chwili importu.
     *
     * @return int|null UID, np. 3
     */
    public function getImapUid(): ?int {
        return $this->imapUid;
    }

    /**
     * Ustawia UID wiadomości w folderze IMAP.
     *
     * @param int|null $imapUid UID, np. 3
     *
     * @return $this
     */
    public function setImapUid(?int $imapUid): static {
        $this->imapUid = $imapUid;

        return $this;
    }

    /**
     * Zwraca metadane załączników wiadomości.
     *
     * @return Collection<int, Attachment> Kolekcja, np. [Attachment("faktura.pdf"), Attachment("logo.png")]
     */
    public function getAttachments(): Collection {
        return $this->attachments;
    }

    /**
     * Dodaje metadane załącznika (idempotentnie) i synchronizuje stronę właścicielską.
     *
     * @param Attachment $attachment Załącznik, np. Attachment("faktura.pdf")
     *
     * @return $this
     */
    public function addAttachment(Attachment $attachment): static {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setMessage($this);
        }

        return $this;
    }

    /**
     * Usuwa metadane załącznika i zeruje jego powiązanie z tą wiadomością.
     *
     * @param Attachment $attachment Załącznik, np. Attachment("faktura.pdf")
     *
     * @return $this
     */
    public function removeAttachment(Attachment $attachment): static {
        if ($this->attachments->removeElement($attachment)) {
            // Strona właścicielska to Attachment — zerujemy, jeśli wciąż wskazuje na nas.
            if ($attachment->getMessage() === $this) {
                $attachment->setMessage(null);
            }
        }

        return $this;
    }

    /**
     * Etykieta wiadomości w UI (temat, a w razie braku — identyfikator).
     *
     * @return string Etykieta, np. "Test z załącznikiem"
     */
    public function __toString(): string {
        return $this->subject ?? sprintf('Message #%s', $this->id ?? '?');
    }
}
