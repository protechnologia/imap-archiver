<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\User;

/**
 * Budowanie encji na potrzeby testów.
 *
 * Mieszka w `tests/Fixtures/` obok katalogu `eml/` z surowymi plikami `.eml`: jedno miejsce na
 * wszystko, z czego testy biorą dane — niezależnie od tego, czy powstają w kodzie (encje), czy
 * leżą jako bajty na dysku (fixtury `.eml` dla `MessageFactoryTest`).
 *
 * Świadomie zamiast `DoctrineFixturesBundle`: testy potrzebują 2-3 rekordów ustawionych pod
 * konkretny przypadek (np. wiadomość BEZ daty), a nie wspólnego zestawu danych — plik fikstur
 * i tak trzeba by czytać przy każdej asercji, żeby wiedzieć, co jest w bazie.
 *
 * Metody wypełniają tylko pola wymagane przez schemat; to, co jest istotne dla danego testu,
 * podaje się argumentem. Encje NIE są zapisywane — `persist()`/`flush()` robi test.
 */
final class EntityFactory {
    /** Licznik do generowania unikalnych `sha256` (UNIQUE(account_id, sha256)). */
    private static int $sequence = 0;

    /**
     * Konto IMAP z atrapami poświadczeń.
     *
     * @param string $label Etykieta widoczna w UI i sortowana w liście kont, np. "Poczta firmowa"
     *
     * @return MailAccount Gotowe do `persist()`
     */
    public static function account(string $label = 'Konto testowe'): MailAccount {
        return (new MailAccount())
            ->setLabel($label)
            ->setHost('imap.example.com')
            ->setPort(993)
            ->setImapLogin('skrzynka@example.com')
            ->setFolder('INBOX')
            ->setSecret('atrapa-hasla');
    }

    /**
     * Użytkownik z gotowym hashem hasła (testy logują się przez `loginUser()`, nie formularzem).
     *
     * @param string       $email Adres e-mail = identyfikator w security, np. "user@example.com"
     * @param list<string> $roles Role BEZ `ROLE_USER` (dokłada je getter), np. ["ROLE_ADMIN"]
     *
     * @return User Gotowy do `persist()`
     */
    public static function user(string $email, array $roles = []): User {
        return (new User())
            ->setEmail($email)
            ->setRoles($roles)
            ->setPassword('$2y$13$atrapa.hasha.ktorego.nikt.nie.weryfikuje');
    }

    /**
     * Wiadomość w indeksie — pola tożsamościowe (`sha256`, `archivePath`) generowane unikalnie.
     *
     * @param MailAccount             $account Konto, z którego „pochodzi" wiadomość
     * @param string                  $subject Temat, np. "Faktura VAT 12/2026"
     * @param \DateTimeImmutable|null $date    Nagłówek `Date`; null = wiadomość bez daty
     *
     * @return Message Gotowa do `persist()`
     */
    public static function message(MailAccount $account, string $subject = 'Wiadomość', ?\DateTimeImmutable $date = null): Message {
        $sha256 = hash('sha256', 'fixture-' . ++self::$sequence);

        return (new Message())
            ->setAccount($account)
            ->setSubject($subject)
            ->setFromName('Anna Kowalska')
            ->setFromEmail('anna@example.com')
            ->setDate($date)
            ->setSize(1024)
            ->setSha256($sha256)
            ->setArchivePath(sprintf('%s/2026/06/%s.eml', $account->getId() ?? 0, $sha256))
            ->setVerified(true);
    }

    /**
     * Wstawia identyfikator do encji, która nie została zapisana w bazie.
     *
     * Potrzebne WYŁĄCZNIE w testach jednostkowych (bez bazy): `MessageVoter` porównuje konta po
     * `getId()`, a encja świeżo z `new` ma tam null i każde porównanie wychodziłoby na remis.
     * Encje mają `id` prywatne i bez settera — celowo, bo w produkcji nadaje je wyłącznie baza.
     *
     * @param object $entity Encja, np. MailAccount
     * @param int    $id     Identyfikator do wstawienia, np. 67
     */
    public static function withId(object $entity, int $id): void {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
