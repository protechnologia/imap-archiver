<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailAccount;
use App\Model\ImportSummary;

/**
 * Koordynator importu rocznika do archiwum (etap 3.3).
 *
 * Steruje POLITYKĄ importu: deleguje całą warstwę IMAP do `ImapReader`, a dla każdej pobranej
 * `RawMessage` robi to, co trzeba — zapis do archiwum (idempotentnie po sha256; w dry-run
 * pomijany), liczenie, złożenie `ImportSummary`. Nie wie nic o protokole IMAP.
 *
 * Bezstanowy — gotowy na worker mode (etap 4).
 *
 * PODETAP 3.3b: pobranie + zapis. Idempotencja po DB, indeks `Message`/`Attachment` i weryfikacja
 * (`verified`) dochodzą w 3.3c — wejdą do pętli importu obok zapisu.
 */
final class ImportManager
{
    public function __construct(
        private readonly ImapReader $reader,
        private readonly ArchiveStorage $archiveStorage,
    ) {
    }

    /**
     * Importuje wiadomości z danego roku (po INTERNALDATE) z folderu konta do archiwum.
     *
     * @param MailAccount $account Konto źródłowe, np. MailAccount #67
     * @param int         $year    Rok, np. 2025
     * @param bool        $dryRun  Przebieg próbny (pobiera i liczy, ale nie zapisuje)
     *
     * @return ImportSummary Podsumowanie przebiegu, np. dla roku 2026 z trzema mailami bez błędów:
     *   ImportSummary {
     *       year:       2026,
     *       candidates: 3,
     *       fetched:    3,
     *       errors:     [],
     *       dryRun:     false,
     *   }
     *
     * @throws \RuntimeException                                     gdy folder nie istnieje
     * @throws \Webklex\PHPIMAP\Exceptions\ConnectionFailedException gdy połączenie/logowanie zawiedzie
     */
    public function import(MailAccount $account, int $year, bool $dryRun): ImportSummary {
        $accountId = (int) $account->getId();
        $fetched = 0;
        $errors  = [];

        // ImapReader oddaje kolejne FetchResult leniwie (jedno połączenie na rok). Dwa źródła błędów,
        // oba z etykietą UID-a: porażka pobrania (isOk()==false) — błąd IMAP już oznaczony przez
        // readera; oraz błąd store() — nasz błąd zapisu, łapiemy tu. Błąd „setup" (brak folderu itd.)
        // w ogóle tu nie dojdzie — przerwie iterację wyjątkiem.
        foreach( $this->reader->readYear($account, $year) as $result ) {
            if( ! $result->isOk() ) {
                $errors[] = sprintf('UID %d: %s', $result->uid, $result->error);
                continue;
            }

            try {
                if( ! $dryRun ) {
                    $this->archiveStorage->store($accountId, $result->message->internalDate, $result->message->raw);
                }

                ++$fetched;
            }
            catch (\Throwable $e) {
                $errors[] = sprintf('UID %d: %s', $result->uid, $e->getMessage());
            }
        }

        // Każdy FetchResult trafia dokładnie w jedno: porażka pobrania / zapisany / błąd zapisu —
        // więc suma = ile znalazł SEARCH. (Błąd „setup" nie dochodzi tu — przerwałby iterację wyżej.)
        return new ImportSummary(
            year:       $year,
            candidates: $fetched + count($errors),
            fetched:    $fetched,
            errors:     $errors,
            dryRun:     $dryRun,
        );
    }
}
