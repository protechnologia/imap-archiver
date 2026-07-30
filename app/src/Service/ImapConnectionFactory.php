<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailAccount;
use App\Enum\AuthType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Buduje POŁĄCZONEGO klienta IMAP (webklex/php-imap) z poświadczeń `MailAccount`.
 *
 * Jeden punkt prawdy dla warstwy połączenia: mapuje pola encji na konfigurację
 * klienta i deszyfruje `secret` (przezroczyście przez typ Doctrine `encrypted_string`).
 *
 * Bezstanowy — gotowy na worker mode (etap 4): nie trzyma stanu między wywołaniami.
 * Etap 2: obsługujemy wyłącznie `AuthType::Password`. XOAUTH2 — później (osobny podetap).
 */
class ImapConnectionFactory {
    public function __construct(
        #[Autowire('%env(bool:IMAP_VALIDATE_CERT)%')]
        private readonly bool $validateCert,
    ) {
    }

    /**
     * Tworzy i ŁĄCZY klienta dla danego konta.
     *
     * Wołający odpowiada za rozłączenie — najlepiej `try { ... } finally { $client->disconnect(); }`.
     *
     * @throws \InvalidArgumentException gdy konto nie używa uwierzytelniania hasłem
     * @throws \Webklex\PHPIMAP\Exceptions\ConnectionFailedException gdy nie uda się połączyć / zalogować
     */
    public function connect(MailAccount $account): Client {
        if ($account->getAuthType() !== AuthType::Password) {
            throw new \InvalidArgumentException(sprintf(
                'Konto "%s" używa uwierzytelniania "%s" — etap 2 obsługuje tylko hasło (XOAUTH2 będzie później).',
                $account->getLabel(),
                $account->getAuthType()->value,
            ));
        }

        $clientManager = new ClientManager();
        $client = $clientManager->make([
            'host'           => $account->getHost(),
            'port'           => $account->getPort(),
            'protocol'       => 'imap',
            'encryption'     => $account->getPort() === 143 ? 'tls' : 'ssl', // Port 143 = STARTTLS, w pozostałych przypadkach implicit SSL (993 i typowe).
            'validate_cert'  => $this->validateCert,
            'username'       => $account->getImapLogin(),
            'password'       => (string) $account->getSecret(),
            'authentication' => null,
        ]);

        $client->connect();

        return $client;
    }
}
