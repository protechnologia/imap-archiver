<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ArchiveStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `ArchiveStorage` — zapis i odczyt ŹRÓDŁA PRAWDY archiwum (etap 3.2).
 *
 * Jednostkowo, ale na PRAWDZIWYM systemie plików w katalogu tymczasowym: przedmiotem testu są
 * bajty na dysku i układ katalogów, więc atrapa `Filesystem` sprawdzałaby tylko, że wołamy
 * `dumpFile()` — a nie to, co po tym wywołaniu naprawdę leży w archiwum.
 *
 * Najważniejsze przypadki to `testPonownyZapisTychSamychBajtowNieNadpisujePliku()` (plik `.eml`
 * jest niemutowalny) i `testOdczytOddajeBajtyJedenDoJednego()` (żadnego przekodowywania po drodze
 * — inaczej przeliczony `sha256` przestałby się zgadzać i weryfikacja z 3.3 padłaby na zdrowej
 * wiadomości).
 */
class ArchiveStorageTest extends TestCase {
    private const ACCOUNT_ID = 67;

    private string $archiveDir;
    private Filesystem $filesystem;
    private ArchiveStorage $storage;

    protected function setUp(): void {
        $this->filesystem = new Filesystem();
        $this->archiveDir = sys_get_temp_dir() . '/imap-archiver-test-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->archiveDir);

        $this->storage = new ArchiveStorage($this->archiveDir, $this->filesystem);
    }

    protected function tearDown(): void {
        $this->filesystem->remove($this->archiveDir);
    }

    /**
     * Układ archiwum to `<accountId>/<rok>/<mm>/<sha256>.eml`, gdzie rok i miesiąc biorą się
     * z daty maila (INTERNALDATE), a nazwa pliku z treści — stąd naturalna deduplikacja.
     */
    public function testZapisUkladaPlikWedlugKontaDatyISumyKontrolnej(): void {
        $raw = "From: anna@example.com\r\nSubject: Test\r\n\r\nTreść\r\n";

        $archived = $this->storage->store(self::ACCOUNT_ID, new \DateTimeImmutable('2026-06-16 10:15'), $raw);

        $this->assertSame(sprintf('67/2026/06/%s.eml', hash('sha256', $raw)), $archived->relativePath);
        $this->assertSame(hash('sha256', $raw), $archived->sha256);
        $this->assertSame(strlen($raw), $archived->size);
        $this->assertFileExists($this->archiveDir . '/' . $archived->relativePath);
    }

    /**
     * Miesiąc ma być dwucyfrowy — inaczej styczeń wylądowałby w katalogu `1`, a czerwiec w `06`
     * i jedno konto miałoby dwa różne układy katalogów.
     */
    public function testMiesiacJestDwucyfrowy(): void {
        $archived = $this->storage->store(self::ACCOUNT_ID, new \DateTimeImmutable('2026-01-03 00:00'), 'styczeń');

        $this->assertStringStartsWith('67/2026/01/', $archived->relativePath);
    }

    /**
     * Plik `.eml` jest NIEMUTOWALNY: skoro nazwą jest suma kontrolna treści, to istniejący plik
     * o tej nazwie ma już właściwe bajty. Ponowny import (idempotencja z 3.3) nie ma prawa go
     * ruszyć — podmieniamy zawartość na dysku i sprawdzamy, że `store()` jej nie nadpisał.
     */
    public function testPonownyZapisTychSamychBajtowNieNadpisujePliku(): void {
        $raw      = "From: anna@example.com\r\n\r\nOryginał\r\n";
        $archived = $this->storage->store(self::ACCOUNT_ID, new \DateTimeImmutable('2026-06-16 10:15'), $raw);
        $sciezka  = $this->archiveDir . '/' . $archived->relativePath;

        $this->filesystem->dumpFile($sciezka, 'PODMIENIONE');
        $ponownie = $this->storage->store(self::ACCOUNT_ID, new \DateTimeImmutable('2026-06-16 10:15'), $raw);

        $this->assertSame($archived->relativePath, $ponownie->relativePath);
        $this->assertStringEqualsFile($sciezka, 'PODMIENIONE', 'store() nadpisał istniejący plik archiwum');
    }

    /**
     * Dwie różne wiadomości tego samego konta i miesiąca to dwa osobne pliki — dedupikacja
     * działa po TREŚCI, a nie po katalogu.
     */
    public function testRozneWiadomosciTegoSamegoMiesiacaTrafiajaDoOsobnychPlikow(): void {
        $data = new \DateTimeImmutable('2026-06-16 10:15');

        $pierwsza = $this->storage->store(self::ACCOUNT_ID, $data, 'pierwsza wiadomość');
        $druga    = $this->storage->store(self::ACCOUNT_ID, $data, 'druga wiadomość');

        $this->assertNotSame($pierwsza->relativePath, $druga->relativePath);
        $this->assertFileExists($this->archiveDir . '/' . $pierwsza->relativePath);
        $this->assertFileExists($this->archiveDir . '/' . $druga->relativePath);
    }

    /**
     * Odczyt musi oddać dokładnie te bajty, które zapisano — z `CRLF` i sekwencjami, które nie
     * są poprawnym UTF-8 włącznie. Każde „poprawianie" treści po drodze rozjechałoby przeliczony
     * `sha256` z nazwą pliku, czyli wywróciło weryfikację przed kasowaniem z serwera (etap 6).
     */
    public function testOdczytOddajeBajtyJedenDoJednego(): void {
        $raw = "Subject: =?UTF-8?B?VGVzdA==?=\r\n\r\n\x00\xC3\x28 binarny \xFF ogon\r\n";

        $archived = $this->storage->store(self::ACCOUNT_ID, new \DateTimeImmutable('2026-06-16 10:15'), $raw);

        $this->assertSame($raw, $this->storage->read($archived->relativePath));
        $this->assertSame(hash('sha256', $raw), hash('sha256', $this->storage->read($archived->relativePath)));
    }

    public function testSciezkaBezwzglednaJestWewnatrzKataloguArchiwum(): void {
        $this->assertSame(
            $this->archiveDir . '/67/2026/06/plik.eml',
            $this->storage->path('67/2026/06/plik.eml'),
        );
    }

    /**
     * `archivePath` przychodzi z bazy, więc traktujemy go jak wejście: wyjście poza katalog
     * archiwum musi zostać odrzucone, zanim dotknie systemu plików.
     *
     * @param string $sciezka Ścieżka do odrzucenia, np. "../../etc/passwd"
     */
    #[DataProvider('niebezpieczneSciezki')]
    public function testNiebezpiecznaSciezkaJestOdrzucana(string $sciezka): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->path($sciezka);
    }

    /**
     * @return iterable<string, array{string}> Ścieżki, które nie mają prawa przejść
     */
    public static function niebezpieczneSciezki(): iterable {
        yield 'wyjście w górę drzewa'      => ['../../etc/passwd'];
        yield 'wyjście w środku ścieżki'   => ['67/../../etc/passwd'];
        yield 'ścieżka bezwzględna'        => ['/etc/passwd'];
        yield 'pusta ścieżka'              => [''];
    }

    /**
     * Brak pliku w archiwum to awaria, nie pusty wynik — weryfikacja z 3.3 ma się o to potknąć
     * głośno, bo oznacza rozjazd między indeksem a źródłem prawdy.
     */
    public function testOdczytNieistniejacegoPlikuRzucaWyjatek(): void {
        $this->expectException(IOException::class);

        $this->storage->read('67/2026/06/' . str_repeat('a', 64) . '.eml');
    }

    /**
     * Konto bez ID (encja niezapisana → `(int) null` = 0) nie ma prawa cicho wylądować
     * w katalogu `0/` — to znak, że w warstwę wyżej wkradł się błąd.
     */
    public function testNiedodatnieIdKontaJestOdrzucane(): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->store(0, new \DateTimeImmutable('2026-06-16 10:15'), 'cokolwiek');
    }
}
