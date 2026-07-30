<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Wynik importu rocznika do archiwum (etap 3.3).
 *
 * DTO wyniku: niesie podsumowanie przebiegu z serwisu `ImportManager` do komendy — bez zachowania,
 * sam transport liczb. Każdy kandydat (mail zwrócony przez SEARCH) trafia w DOKŁADNIE jedno wiadro:
 * `imported` (nowy: zapis `.eml` + indeks) / `skipped` (duplikat już w DB) / błąd (`errors`). `verified`
 * to podzbiór `imported` (plik odczytany z dysku, przeliczony `sha256` się zgodził). Zawsze zachodzi
 * `candidates == imported + skipped + count(errors)` oraz `verified <= imported`.
 *
 * Przykładowy stan (po imporcie roku 2026 z trzema nowymi mailami bez błędów):
 *   ImportSummary {
 *       year:       2026,
 *       candidates: 3,
 *       imported:   3,
 *       skipped:    0,
 *       verified:   3,
 *       errors:     [],
 *       dryRun:     false,
 *   }
 */
readonly class ImportSummary {
    /**
     * @param list<string> $errors Komunikaty błędów per wiadomość (import leci dalej mimo błędu jednej)
     */
    public function __construct(
        /** Rok importu, np. 2025. */
        public int $year,

        /** Ile wiadomości zwrócił SEARCH SINCE/BEFORE (kandydaci wg INTERNALDATE), np. 128. */
        public int $candidates,

        /** Ile nowych zapisano do archiwum + zaindeksowano (w dry-run: ile BY zaimportowano), np. 120. */
        public int $imported,

        /** Ile pominięto jako duplikaty już obecne w DB (idempotencja po `sha256`), np. 8. */
        public int $skipped,

        /** Ile z `imported` przeszło weryfikację (plik + zgodny checksum); 0 w dry-run, np. 120. */
        public int $verified,

        /** @var list<string> Błędy per wiadomość, np. ["UID 7: serwer nie zwrócił źródła"]. */
        public array $errors,

        /** Czy to przebieg próbny (bez zapisu do archiwum i DB). */
        public bool $dryRun,
    ) {
    }

}
