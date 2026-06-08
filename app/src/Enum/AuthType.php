<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Sposób uwierzytelniania konta na serwerze IMAP.
 *
 * Jeden punkt prawdy dla całej aplikacji: warstwa IMAP (etap 2) i szyfrowanie
 * poświadczeń (etap 1.4) rozgałęziają logikę po tej wartości.
 */
enum AuthType: string
{
    /** Klasyczny login + hasło (np. własny serwer IMAP / Dovecot). */
    case Password = 'password';

    /** Token OAuth2 podawany mechanizmem XOAUTH2 (Gmail, Microsoft 365). */
    case Xoauth2 = 'xoauth2';

    /** Etykieta po polsku do UI (EasyAdmin — etap 1.5). */
    public function label(): string
    {
        return match ($this) {
            self::Password => 'Hasło',
            self::Xoauth2 => 'XOAUTH2',
        };
    }
}
