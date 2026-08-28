<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Message;
use App\Service\ArchiveStorage;
use App\Service\MailBodyReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `MailBodyReader` — wyciąganie treści z `.eml` do podglądu (etap 4.5).
 *
 * Jednostkowo, ale na PRAWDZIWYCH plikach i prawdziwym parserze (tak samo jak `ArchiveStorageTest`
 * i `MessageFactoryTest`): przedmiotem testu jest to, co webklex wyciąga z konkretnych bajtów MIME,
 * więc atrapa parsera sprawdzałaby wyłącznie nasze wyobrażenie o nim. Fixtury `.eml` są wspólne
 * z `MessageFactoryTest` — te same bajty opisują zachowanie obu klas.
 *
 * Najważniejsze przypadki:
 *  - `testMailHtmlOnlyNieDostajeTekstuZUdawania()` — pilnuje POWODU powstania tej klasy: indeks
 *    (`Message.body`) trzyma dla takiego maila ŹRÓDŁO HTML-a, które podgląd pokazywał jako ścianę
 *    tagów. Czytnik ma oddać `html`, a `text` zostawić pusty, żeby przełącznik wiedział prawdę.
 *  - `testBrakPlikuWArchiwumDajePustaTresc()` — brak pliku to brak treści, BEZ zapasu z bazy.
 *    Archiwum jest źródłem prawdy; podgląd nie ma prawa sugerować, że treść ocalała.
 */
class MailBodyReaderTest extends TestCase {
    private const ACCOUNT_ID = 67;

    private string $archiveDir;
    private Filesystem $filesystem;
    private ArchiveStorage $storage;
    private MailBodyReader $reader;

    protected function setUp(): void {
        $this->filesystem = new Filesystem();
        $this->archiveDir = sys_get_temp_dir() . '/imap-archiver-body-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->archiveDir);

        $this->storage = new ArchiveStorage($this->archiveDir, $this->filesystem);
        $this->reader  = new MailBodyReader($this->storage);
    }

    protected function tearDown(): void {
        $this->filesystem->remove($this->archiveDir);
    }

    /**
     * `multipart/alternative` — obie części są dostępne, bo dopiero komplet pozwala przełącznikowi
     * zaproponować wybór zamiast blokady.
     */
    public function testMultipartAlternativeDajeObaWarianty(): void {
        $body = $this->reader->read($this->givenArchived('multipart-alternative.eml'));

        $this->assertSame('To jest wersja tekstowa.', $body->text);
        $this->assertStringContainsString('To jest wersja HTML.', (string) $body->html);
        $this->assertTrue($body->has('text'));
        $this->assertTrue($body->has('html'));
    }

    public function testMailHtmlOnlyNieDostajeTekstuZUdawania(): void {
        $body = $this->reader->read($this->givenArchived('html-only.eml'));

        $this->assertNull($body->text, 'Brak części tekstowej ma być widoczny jako null, nie podstawiony HTML');
        $this->assertStringContainsString('Wyłącznie HTML.', (string) $body->html);
    }

    public function testMailTekstowyNieDostajeHtmluZUdawania(): void {
        $body = $this->reader->read($this->givenArchived('simple.eml'));

        $this->assertSame('Cześć, w załączniku przesyłam fakturę.', $body->text);
        $this->assertNull($body->html);
    }

    /**
     * Rekord w bazie wskazuje plik, którego nie ma (nieudany bind mount, ręczne sprzątanie,
     * zaślepki sprzed 4.5). Podgląd ma powiedzieć „nie ma treści", a nie wywalić skrzynki 500.
     */
    public function testBrakPlikuWArchiwumDajePustaTresc(): void {
        $message = new Message();
        $message->setArchivePath('67/2026/06/' . str_repeat('a', 64) . '.eml');

        $body = $this->reader->read($message);

        $this->assertTrue($body->isEmpty());
        $this->assertNull($body->resolveVariant('html'));
    }

    /**
     * Ścieżka z bazy jest danymi wejściowymi jak każde inne — `ArchiveStorage` odrzuca próbę
     * wyjścia poza archiwum, a czytnik ma to potraktować jak brak pliku, nie przepuścić wyjątku.
     */
    public function testSciezkaWychodzacaPozaArchiwumNiePrzeciekaWyjatkiem(): void {
        $message = new Message();
        $message->setArchivePath('../../etc/passwd');

        $this->assertTrue($this->reader->read($message)->isEmpty());
    }

    /**
     * Zapisuje fixturę `.eml` w archiwum i oddaje wskazującą na nią wiadomość.
     *
     * @param string $fixture Nazwa pliku z `tests/Fixtures/eml/`, np. "html-only.eml"
     *
     * @return Message Wiadomość z ustawionym `archivePath`
     */
    private function givenArchived(string $fixture): Message {
        $raw      = (string) file_get_contents(__DIR__ . '/../../Fixtures/eml/' . $fixture);
        $archived = $this->storage->store(self::ACCOUNT_ID, new \DateTimeImmutable('2026-06-16 10:00'), $raw);

        $message = new Message();
        $message->setArchivePath($archived->relativePath);

        return $message;
    }
}
