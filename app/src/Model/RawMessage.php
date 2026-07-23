<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Surowa wiadomość pobrana z IMAP: UID + bajtowo-wierne źródło `.eml` + INTERNALDATE.
 *
 * Wartość przekraczająca granicę czytnik IMAP (`ImapReadSession`) → koordynator (`ImapImporter`).
 * `raw` to pełne RFC822 (nagłówki + `\r\n\r\n` + treść), nad którym liczymy sha256 i który ląduje
 * w archiwum. `internalDate` (data przyjęcia przez serwer, ze strefą jak zwrócił serwer) steruje
 * kubełkiem `<rok>/<mm>` — patrz README → „Ciekawostki techniczne".
 *
 * Przykładowy stan:
 *   RawMessage {
 *       uid:          3,
 *       raw:          "Return-Path: <...>\r\n...\r\n\r\n<treść>",
 *       internalDate: new \DateTimeImmutable('2026-06-16 07:58:58 +0200'),
 *   }
 */
final readonly class RawMessage {
    public function __construct(
        /** UID wiadomości w folderze IMAP, np. 3. */
        public int $uid,

        /** Pełne, bajtowo-wierne źródło RFC822 (`.eml`). */
        public string $raw,

        /** INTERNALDATE — data przyjęcia przez serwer (steruje kubełkiem `<rok>/<mm>`). */
        public \DateTimeImmutable $internalDate,
    ) {
    }

}
