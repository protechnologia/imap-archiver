<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Wynik importu rocznika do archiwum (etap 3.3).
 *
 * DTO wyniku: niesie podsumowanie przebiegu z serwisu `ImapImporter` do komendy —
 * bez zachowania, sam transport liczb. Rośnie wraz z podetapami 3.3: 3.3a liczyła tylko
 * kandydatów, 3.3b dołożyła pobrane + błędy, 3.3c dołoży pominięte-duplikaty i zweryfikowane.
 *
 * Przykładowy stan (3.3b, po imporcie roku 2026 z trzema mailami bez błędów):
 *   ImportSummary {
 *       year:       2026,
 *       candidates: 3,
 *       fetched:    3,
 *       errors:     [],
 *       dryRun:     false,
 *   }
 */
final readonly class ImportSummary {

    /**
     * @param list<string> $errors Komunikaty błędów per wiadomość (import leci dalej mimo błędu jednej)
     */
    public function __construct(
        /** Rok importu, np. 2025. */
        public int $year,

        /** Ile wiadomości zwrócił SEARCH SINCE/BEFORE (kandydaci wg INTERNALDATE), np. 128. */
        public int $candidates,

        /** Ile pobrano i zapisano do archiwum (w dry-run: pobrano bez zapisu), np. 128. */
        public int $fetched,

        /** @var list<string> Błędy per wiadomość, np. ["UID 7: serwer nie zwrócił źródła"]. */
        public array $errors,

        /** Czy to przebieg próbny (bez zapisu do archiwum i DB). */
        public bool $dryRun,
    ) {
    }

}
