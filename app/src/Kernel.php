<?php

declare(strict_types=1);

namespace App;

use App\Doctrine\Type\EncryptedStringType;
use App\Security\CredentialEncryptor;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel {
    use MicroKernelTrait;

    public function boot(): void {
        parent::boot();

        // Typy Doctrine to globalne singletony bez DI. Rejestrujemy `encrypted_string`
        // tu (nie w doctrine.yaml — tamta ścieżka nadpisałaby instancję przy budowie
        // połączenia i zgubiła encryptor) i wstrzykujemy w nią CredentialEncryptor.
        // Raz na proces; w worker mode boot() powtarza się przy reset, a encryptor jest
        // bezstanowy, więc ponowne ustawienie jest bezpieczne.
        if (!Type::hasType(EncryptedStringType::NAME)) {
            Type::addType(EncryptedStringType::NAME, EncryptedStringType::class);
        }

        /** @var EncryptedStringType $type */
        $type = Type::getType(EncryptedStringType::NAME);
        $type->setEncryptor($this->container->get(CredentialEncryptor::class));
    }
}
