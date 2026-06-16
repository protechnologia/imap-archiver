<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Metadane załącznika wiadomości. Etap 3.
 *
 * Tylko METADANE — surowe bajty załącznika zostają w pliku `.eml` (źródło prawdy),
 * nie duplikujemy ich na dysk. Renderowanie/pobieranie załącznika nastąpi z `.eml`
 * dopiero przy podglądzie (etap 5).
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachment')]
class Attachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Wiadomość, do której należy załącznik. Strona właścicielska (FK message_id). */
    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    /** Nazwa pliku załącznika; bywa NULL (np. inline bez `filename`). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mimeType = null;

    /** Rozmiar w bajtach; NULL gdy nieznany. */
    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    /** Opcjonalny SHA-256 (hex) bajtów załącznika — liczony przy imporcie, gdy dostępny. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    /**
     * Zwraca identyfikator załącznika (null przed pierwszym zapisem).
     *
     * @return int|null Identyfikator, np. 15
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Zwraca wiadomość, do której należy załącznik.
     *
     * @return Message|null Wiadomość, np. Message #42 ("Test z załącznikiem")
     */
    public function getMessage(): ?Message
    {
        return $this->message;
    }

    /**
     * Ustawia wiadomość, do której należy załącznik.
     *
     * @param Message|null $message Wiadomość, np. Message #42 ("Test z załącznikiem")
     *
     * @return $this
     */
    public function setMessage(?Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Zwraca nazwę pliku załącznika (lub null dla inline bez `filename`).
     *
     * @return string|null Nazwa pliku, np. "faktura.pdf"
     */
    public function getFilename(): ?string
    {
        return $this->filename;
    }

    /**
     * Ustawia nazwę pliku załącznika.
     *
     * @param string|null $filename Nazwa pliku, np. "faktura.pdf"
     *
     * @return $this
     */
    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Zwraca typ MIME załącznika.
     *
     * @return string|null Typ MIME, np. "application/pdf"
     */
    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Ustawia typ MIME załącznika.
     *
     * @param string|null $mimeType Typ MIME, np. "application/pdf"
     *
     * @return $this
     */
    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    /**
     * Zwraca rozmiar załącznika w bajtach (lub null, gdy nieznany).
     *
     * @return int|null Rozmiar w bajtach, np. 84210
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * Ustawia rozmiar załącznika w bajtach.
     *
     * @param int|null $size Rozmiar w bajtach, np. 84210
     *
     * @return $this
     */
    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Zwraca opcjonalny SHA-256 (hex) bajtów załącznika.
     *
     * @return string|null SHA-256, np. "a1b2c3d4…" (64 znaki hex)
     */
    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    /**
     * Ustawia opcjonalny SHA-256 (hex) bajtów załącznika.
     *
     * @param string|null $sha256 SHA-256, np. "a1b2c3d4…" (64 znaki hex)
     *
     * @return $this
     */
    public function setSha256(?string $sha256): static
    {
        $this->sha256 = $sha256;

        return $this;
    }

    /**
     * Etykieta załącznika w UI — nazwa pliku, a w razie braku — identyfikator.
     *
     * @return string Etykieta, np. "faktura.pdf"
     */
    public function __toString(): string
    {
        return $this->filename ?? sprintf('Attachment #%s', $this->id ?? '?');
    }
}
