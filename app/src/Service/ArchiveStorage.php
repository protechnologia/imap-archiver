<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\ArchivedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Magazyn surowych `.eml` — ŹRÓDŁO PRAWDY archiwum (etap 3.2).
 *
 * Zapisuje bajty w układzie content-addressed `<accountId>/<rok>/<mm>/<sha256>.eml` pod
 * `ARCHIVE_DIR` (w kontenerze `/archive`, podpięty bind mountem z hosta — patrz README).
 * Nazwa pliku = `sha256` → naturalna deduplikacja i weryfikowalność (te same bajty = ten
 * sam plik). Zapis jest ATOMOWY: `Filesystem::dumpFile()` pisze do `*.tmp` w tym samym
 * katalogu i robi `rename` — pod kanoniczną ścieżką nigdy nie wyląduje plik obcięty (crash
 * zostawia co najwyżej osierocony temp, nie uszkodzone źródło prawdy).
 *
 * Bezstanowy — gotowy na worker mode (etap 4). Nie zna ścieżki hosta; operuje tylko na
 * `ARCHIVE_DIR` (rozdział `ARCHIVE_DIR` vs `ARCHIVE_HOST_DIR` — patrz README/CLAUDE).
 */
final class ArchiveStorage {
    /**
     * @param string     $archiveDir Katalog archiwum widziany w kontenerze, np. "/archive"
     * @param Filesystem $filesystem Komponent Symfony do operacji na plikach (atomowy zapis)
     */
    public function __construct(
        #[Autowire('%env(ARCHIVE_DIR)%')]
        private readonly string $archiveDir,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Zapisuje surowy `.eml` i zwraca jego adres w archiwum.
     *
     * Idempotentnie po treści: jeśli plik o danym `sha256` już istnieje, nie nadpisuje.
     *
     * @param int                $accountId ID konta MailAccount, np. 67
     * @param \DateTimeImmutable $date      Data maila (steruje katalogiem rok/mm)
     * @param string             $rawEml    Surowe bajty wiadomości (pełne RFC822)
     *
     * @return ArchivedFile Ścieżka względna + sha256 + rozmiar
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException gdy zapis się nie powiedzie
     */
    public function store(int $accountId, \DateTimeImmutable $date, string $rawEml): ArchivedFile {
        $sha256 = hash('sha256', $rawEml);
        $relativePath = $this->buildRelativePath($accountId, $date, $sha256);
        $absolutePath = $this->absolutePath($relativePath);

        // Content-addressed: te same bajty = ten sam plik. Jeśli już jest, nie ruszamy.
        // dumpFile() jest atomowy (temp + rename) i sam tworzy brakujące katalogi.
        if (!$this->filesystem->exists($absolutePath)) {
            $this->filesystem->dumpFile($absolutePath, $rawEml);
        }

        return new ArchivedFile($relativePath, $sha256, strlen($rawEml));
    }

    /**
     * Zwraca bezwzględną ścieżkę pliku z archiwum (do odczytu/weryfikacji).
     *
     * @param string $relativePath Ścieżka względna, np. "67/2026/06/6f04….eml"
     *
     * @return string Ścieżka bezwzględna, np. "/archive/67/2026/06/6f04….eml"
     */
    public function path(string $relativePath): string {
        return $this->absolutePath($this->assertSafeRelative($relativePath));
    }

    /**
     * Czyta surowe bajty `.eml` z archiwum (np. do ponownego policzenia `sha256` w 3.3).
     *
     * @param string $relativePath Ścieżka względna, np. "67/2026/06/6f04….eml"
     *
     * @return string Surowe bajty `.eml`
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException gdy pliku nie da się odczytać
     */
    public function read(string $relativePath): string {
        return $this->filesystem->readFile($this->path($relativePath));
    }

    /**
     * Składa względną ścieżkę archiwum `<accountId>/<rok>/<mm>/<sha256>.eml` i waliduje wejście.
     *
     * @param int                $accountId ID konta, np. 67
     * @param \DateTimeImmutable $date      Data maila (rok/mm)
     * @param string             $sha256    SHA-256 (hex), 64 znaki
     *
     * @return string Ścieżka względna, np. "67/2026/06/6f04….eml"
     *
     * @throws \InvalidArgumentException gdy accountId niedodatni lub sha256 nie jest 64 hex
     */
    private function buildRelativePath(int $accountId, \DateTimeImmutable $date, string $sha256): string {
        if ($accountId <= 0) {
            throw new \InvalidArgumentException(sprintf('accountId musi być dodatni, dostałem %d.', $accountId));
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('sha256 musi być 64 znakami hex.');
        }

        return sprintf('%d/%s/%s/%s.eml', $accountId, $date->format('Y'), $date->format('m'), $sha256);
    }

    /**
     * Mapuje ścieżkę względną na bezwzględną w obrębie `ARCHIVE_DIR`.
     *
     * @param string $relativePath Ścieżka względna, np. "67/2026/06/6f04….eml"
     *
     * @return string Ścieżka bezwzględna, np. "/archive/67/2026/06/6f04….eml"
     */
    private function absolutePath(string $relativePath): string {
        return rtrim($this->archiveDir, '/') . '/' . $relativePath;
    }

    /**
     * Zabezpiecza przed path traversal ścieżki przychodzące z DB (np. `Message::archivePath`).
     *
     * @param string $relativePath Ścieżka względna do sprawdzenia
     *
     * @return string Ta sama ścieżka, jeśli bezpieczna
     *
     * @throws \InvalidArgumentException gdy ścieżka jest pusta, absolutna lub zawiera ".."
     */
    private function assertSafeRelative(string $relativePath): string {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException(sprintf('Niebezpieczna ścieżka względna: "%s".', $relativePath));
        }

        return $relativePath;
    }
}
