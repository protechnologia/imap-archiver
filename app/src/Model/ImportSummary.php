<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Wynik importu rocznika do archiwum (etap 3.3).
 *
 * DTO wyniku: niesie podsumowanie przebiegu z serwisu `ImapImporter` do komendy —
 * bez zachowania, sam transport liczb. Rośnie wraz z podetapami 3.3: 3.3a liczy tylko
 * kandydatów (UID-y z SEARCH), 3.3b/3.3c dołożą pobrane / pominięte / zweryfikowane / błędy.
 *
 * Przykładowy stan (3.3a, po `--dry-run` dla roku 2026 z trzema mailami):
 *   ImportSummary {
 *       year:       2026,
 *       candidates: 3,
 *       dryRun:     true,
 *   }
 */
final readonly class ImportSummary {

    public function __construct(
        /** Rok importu, np. 2025. */
        public int $year,

        /** Ile wiadomości zwrócił SEARCH SINCE/BEFORE (INTERNALDATE w roku), np. 128. */
        public int $candidates,

        /** Czy to przebieg próbny (bez zapisu do archiwum i DB). */
        public bool $dryRun,
    ) {
    }
    
}
