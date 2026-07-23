<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Formatuje rozmiar w bajtach na czytelną jednostkę (B/KB/MB/GB). Etap 3.4.
 *
 * Jedno miejsce formatowania rozmiarów w całej aplikacji — używa go `formatValue()` pól EasyAdmin
 * (rozmiar wiadomości) oraz filtr Twiga `|bytes` (rozmiary załączników w szablonie). Dzięki temu
 * liczby wyglądają tak samo niezależnie od tego, czy renderuje je PHP czy szablon.
 *
 * Czysty helper bez stanu i zależności — stąd metoda statyczna i brak rejestracji jako usługa
 * (patrz `exclude` w `config/services.yaml`).
 */
final class ByteFormatter {
    /** Czysty helper — nie instancjonujemy. */
    private function __construct() {
    }

    /**
     * Zwraca rozmiar w czytelnej jednostce, z polskim separatorem dziesiętnym.
     *
     * @param int|null $bytes Rozmiar w bajtach, np. 49189 (null = nieznany)
     *
     * @return string Sformatowany rozmiar, np. "48,0 KB"; "—" gdy nieznany
     */
    public static function humanize(?int $bytes): string {
        if ($bytes === null) {
            return '—';
        }

        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%s %s', number_format($value, 1, ',', ' '), $units[$unit]);
    }
}
