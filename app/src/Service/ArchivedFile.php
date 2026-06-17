<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Wynik zapisu do archiwum: gdzie plik leży (ścieżka względna) i czym jest (sha256, rozmiar).
 *
 * `relativePath` jest względny wobec katalogu archiwum (`ARCHIVE_DIR`) — trafia 1:1 do
 * `Message::archivePath`. `sha256` policzono nad dokładnie tymi bajtami, które zapisano.
 */
final readonly class ArchivedFile
{
    public function __construct(
        /** Ścieżka względna w archiwum, np. "67/2026/06/6f041317c753….eml". */
        public string $relativePath,
        /** SHA-256 (hex) zapisanych bajtów, np. "6f041317c753…e1" (64 znaki). */
        public string $sha256,
        /** Rozmiar zapisanych bajtów, np. 49189. */
        public int $size,
    ) {
    }
}
