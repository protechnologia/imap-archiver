<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Security\CredentialEncryptor;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type przezroczyście szyfrujący kolumnę przez CredentialEncryptor.
 *
 * W PHP pole jest jawnym stringiem; w bazie leży szyfrogram. Szyfrowanie wpięte
 * na poziomie DBAL (convert*Value), więc działa dla persist/flush i hydratacji.
 *
 * Encryptor wstrzykiwany jednorazowo po starcie kontenera (Kernel::boot), bo typy
 * Doctrine to globalne singletony bez własnego DI.
 *
 * DBAL 4: brak getName()/requiresSQLCommentHint() — nazwa to wyłącznie klucz rejestracji.
 */
final class EncryptedStringType extends Type
{
    public const NAME = 'encrypted_string';

    private ?CredentialEncryptor $encryptor = null;

    public function setEncryptor(CredentialEncryptor $encryptor): void
    {
        $this->encryptor = $encryptor;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // Szyfrogram base64 — zmienna długość, trzymamy w TEXT.
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->encryptor()->encrypt((string) $value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->encryptor()->decrypt((string) $value);
    }

    private function encryptor(): CredentialEncryptor
    {
        if ($this->encryptor === null) {
            throw new \LogicException(
                'EncryptedStringType nie ma wstrzykniętego CredentialEncryptor — sprawdź Kernel::boot().'
            );
        }

        return $this->encryptor;
    }
}
