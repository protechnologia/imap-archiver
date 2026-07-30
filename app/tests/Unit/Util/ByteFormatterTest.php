<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\ByteFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `ByteFormatter::humanize()` — jedyne miejsce formatowania rozmiarów (etap 3.4).
 *
 * Korzystają z niego pola EasyAdmin i filtr Twiga `|bytes`, więc każda zmiana zachowania rozjeżdża
 * naraz panel i front. Test jest tani, a pilnuje trzech rzeczy, w których łatwo o cichą pomyłkę:
 * granicy przejścia na kolejną jednostkę, POLSKIEGO separatora dziesiętnego (przecinek, nie kropka)
 * i tego, że nieznany rozmiar daje myślnik zamiast „0 B" — bo „0 B" byłoby kłamstwem o pustym pliku.
 */
class ByteFormatterTest extends TestCase {
    /**
     * @param int|null $bytes      Rozmiar w bajtach, np. 49189
     * @param string   $oczekiwany Sformatowana wartość, np. "48,0 KB"
     */
    #[DataProvider('rozmiary')]
    public function testFormatujeRozmiar(?int $bytes, string $oczekiwany): void {
        $this->assertSame($oczekiwany, ByteFormatter::humanize($bytes));
    }

    /**
     * @return iterable<string, array{int|null, string}> Pary wejście → oczekiwane wyjście
     */
    public static function rozmiary(): iterable {
        yield 'nieznany rozmiar'      => [null, '—'];
        yield 'zero'                  => [0, '0 B'];
        yield 'jeden bajt'            => [1, '1 B'];

        // Granica B/KB: 1023 zostaje w bajtach, 1024 przechodzi na kilobajty.
        yield 'ostatni bajt'          => [1023, '1023 B'];
        yield 'pierwszy kilobajt'     => [1024, '1,0 KB'];

        // Polski separator dziesiętny — przecinek, nie kropka.
        yield 'rozmiar maila z 3.4'   => [49189, '48,0 KB'];
        yield 'ułamek kilobajta'      => [1536, '1,5 KB'];

        // Kolejne granice: każda jednostka przełącza się dopiero po przekroczeniu 1024.
        // UWAGA na wartość tuż pod granicą: 1023,999 KB nie kwalifikuje się jeszcze do MB
        // (pętla porównuje przed zaokrągleniem), a zaokrąglenie do jednego miejsca robi z tego
        // „1 024,0 KB" — wygląda dziwnie, ale jest poprawne i nie warto komplikować formattera.
        // Separatorem tysięcy jest SPACJA (`number_format(..., ',', ' ')`).
        yield 'ostatni kilobajt'      => [1024 * 1024 - 1, '1 024,0 KB'];
        yield 'pierwszy megabajt'     => [1024 * 1024, '1,0 MB'];
        yield 'pierwszy gigabajt'     => [1024 ** 3, '1,0 GB'];

        // GB to ostatnia jednostka w tablicy — większe wartości rosną w GB, nie lecą na TB.
        yield 'powyżej ostatniej jednostki' => [5 * 1024 ** 4, '5 120,0 GB'];
    }

    /**
     * Rozmiar ujemny nie powinien powstać (kolumna `size` jest liczbą bajtów zapisanego pliku),
     * ale gdyby trafił z zepsutych danych, ma się sformatować bez wybuchu — widok listy nie może
     * paść przez jedną dziwną wartość.
     */
    public function testUjemnyRozmiarNieWybucha(): void {
        $this->assertSame('-5 B', ByteFormatter::humanize(-5));
    }
}
