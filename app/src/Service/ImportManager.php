<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Model\ArchivedFile;
use App\Model\ImportSummary;
use App\Model\RawMessage;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Koordynator importu rocznika do archiwum (etap 3.3).
 *
 * Steruje POLITYKĄ importu: warstwę IMAP deleguje do `ImapReader`, a dla każdej pobranej `RawMessage`
 * realizuje pipeline z planu etapu 3.3 — idempotencja po DB → zapis `.eml` (źródło prawdy) → indeks
 * `Message`/`Attachment` z tych samych bajtów (`MessageFactory`) → flush → weryfikacja (odczyt z dysku
 * + przeliczony `sha256`) → `verified`. Nie wie nic o protokole IMAP ani o parsowaniu MIME.
 *
 * Bezstanowy (liczniki są lokalne w `import()`) — gotowy na worker mode (etap 4). Batching/`clear()`
 * EM przy dużej skali to etap 4; tu (mały zakres) flushujemy per mail dla prostoty i idempotencji
 * WEWNĄTRZ przebiegu (kolejny duplikat w tym samym roku widzi już zapisany wpis).
 */
final class ImportManager
{
    public function __construct(
        private readonly ImapReader $reader,
        private readonly ArchiveStorage $archiveStorage,
        private readonly MessageFactory $messageFactory,
        private readonly MessageRepository $messages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Importuje wiadomości z danego roku (po INTERNALDATE) z folderu konta do archiwum + indeksu.
     *
     * @param MailAccount $account Konto źródłowe, np. MailAccount #67
     * @param int         $year    Rok, np. 2025
     * @param bool        $dryRun  Przebieg próbny (pobiera i liczy, ale nie zapisuje `.eml` ani DB)
     *
     * @return ImportSummary Podsumowanie przebiegu, np. dla roku 2026 z trzema nowymi mailami:
     *   ImportSummary {
     *       year:       2026,
     *       candidates: 3,
     *       imported:   3,
     *       skipped:    0,
     *       verified:   3,
     *       errors:     [],
     *       dryRun:     false,
     *   }
     *
     * @throws \RuntimeException                                     gdy folder nie istnieje
     * @throws \Webklex\PHPIMAP\Exceptions\ConnectionFailedException gdy połączenie/logowanie zawiedzie
     */
    public function import(MailAccount $account, int $year, bool $dryRun): ImportSummary {
        $accountId  = (int) $account->getId();
        $candidates = 0;
        $imported   = 0;
        $skipped    = 0;
        $verified   = 0;
        $errors     = [];

        // ImapReader oddaje kolejne FetchResult leniwie (jedno połączenie na rok). Błąd „setup"
        // (brak folderu itd.) nie dojdzie tu — przerwie iterację wyjątkiem; łapiemy tylko błędy
        // pojedynczego maila: porażkę pobrania (isOk()==false, już oznaczoną przez readera) oraz
        // nasz błąd zapisu/indeksu/weryfikacji (try/catch wokół przetwarzania).
        foreach( $this->reader->readYear($account, $year) as $result ) {
            ++$candidates;

            if( ! $result->isOk() ) {
                $errors[] = sprintf('UID %d: %s', $result->uid, $result->error);
                continue;
            }

            try {
                $sha256 = hash('sha256', $result->message->raw);

                // Idempotencja: te same bajty na tym koncie już są → pomiń (nie duplikuj).
                if( $this->messages->existsForContent($accountId, $sha256) ) {
                    ++$skipped;
                    continue;
                }

                if( $dryRun ) {
                    // Próbnie: nic nie zapisujemy; liczymy, ile BY zaimportowano (weryfikacja niemożliwa).
                    ++$imported;
                    continue;
                }

                $message = $this->persist($account, $accountId, $result->message);
                ++$imported;

                if( $this->verify($message) ) {
                    ++$verified;
                }
                else {
                    $errors[] = sprintf('UID %d: weryfikacja checksumu nie powiodła się (plik %s)', $result->uid, $message->getArchivePath());
                }
            }
            catch (\Throwable $e) {
                $errors[] = sprintf('UID %d: %s', $result->uid, $e->getMessage());
            }
        }

        return new ImportSummary(
            year:       $year,
            candidates: $candidates,
            imported:   $imported,
            skipped:    $skipped,
            verified:   $verified,
            errors:     $errors,
            dryRun:     $dryRun,
        );
    }

    /**
     * Zapisuje `.eml` do archiwum i utrwala wyprowadzony z niego indeks `Message` (+ załączniki).
     *
     * Kolejność jest istotna: NAJPIERW plik (źródło prawdy), potem indeks zbudowany z DOKŁADNIE tych
     * samych bajtów — DB nigdy nie wskaże pliku, którego nie ma. Zapis pliku jest idempotentny po
     * treści (`ArchiveStorage`), a wpis DB chroni `UNIQUE(account_id, sha256)`.
     *
     * @param MailAccount $account   Konto źródłowe
     * @param int         $accountId ID konta (kubełek katalogu w archiwum)
     * @param RawMessage  $raw       Surowe źródło z IMAP
     *
     * @return Message Utrwalona (z ID), jeszcze niezweryfikowana wiadomość
     */
    private function persist(MailAccount $account, int $accountId, RawMessage $raw): Message {
        $archived = $this->archiveStorage->store($accountId, $raw->internalDate, $raw->raw);
        $message  = $this->messageFactory->fromRaw($account, $raw, $archived);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Weryfikuje wiadomość: odczytuje `.eml` z dysku, przelicza `sha256` i porównuje z indeksem.
     *
     * Zgodność ustawia `verified=true` (i flushuje) — to realizuje warunek bezpiecznego kasowania
     * z etapu 6 (jest w DB + jest plik + checksum się zgadza). Rozjazd zostawia `verified=false`
     * i zwraca `false` (koordynator zgłasza błąd) — nie ufamy plikowi, którego nie potrafimy potwierdzić.
     *
     * @param Message $message Świeżo utrwalona wiadomość ze ścieżką w archiwum
     *
     * @return bool Czy przeliczony `sha256` pliku zgadza się z indeksem
     */
    private function verify(Message $message): bool {
        $onDisk = $this->archiveStorage->read($message->getArchivePath());

        if( hash('sha256', $onDisk) !== $message->getSha256() ) {
            return false;
        }

        $message->setVerified(true);
        $this->em->flush();

        return true;
    }
}
