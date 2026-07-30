<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Attachment;
use App\Entity\MailAccount;
use App\Entity\Message;
use App\Model\ArchivedFile;
use App\Model\RawMessage;
use App\Service\MessageFactory;
use App\Tests\Fixtures\EntityFactory;
use PHPUnit\Framework\TestCase;

/**
 * `MessageFactory` — wyprowadzenie indeksu z surowych bajtów `.eml` (etap 3.3c).
 *
 * To JEDYNE miejsce w projekcie zależne od parsera webkleksa, więc test jest przede wszystkim
 * ubezpieczeniem na podbicie jego wersji: gdyby zmieniło się dekodowanie nagłówków albo wybór
 * części MIME, tematy i nadawcy w całym archiwum cicho by się zepsuły, a `.eml` na dysku
 * wyglądałyby bez zarzutu.
 *
 * Fixtury to prawdziwe pliki `.eml` w `tests/Fixtures/eml/` — o to samo chodzi co w
 * `ArchiveStorageTest`: przedmiotem są konkretne bajty RFC822, a nie nasze wyobrażenie o nich.
 *
 * Najważniejszy przypadek to `testDekodujeNaglowkiZEncodedWords()` — bez `ext-imap` webklex
 * domyślnie NIE dekoduje `=?UTF-8?B?…?=` i temat lądowałby w bazie jako krzaki (gotcha z CLAUDE.md;
 * naprawia to `Config` z dekoderem `iconv`, budowany w konstruktorze fabryki).
 */
class MessageFactoryTest extends TestCase {
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/eml';

    /** Treść załącznika w `with-attachment.eml` (base64 w pliku) — źródło oczekiwanego sha256/rozmiaru. */
    private const ATTACHMENT_PAYLOAD = "%PDF-1.4\nFaktura testowa\n%%EOF\n";

    private MessageFactory $factory;

    protected function setUp(): void {
        $this->factory = new MessageFactory();
    }

    /**
     * Pola tożsamości (`sha256`, `size`, `archivePath`) biorą się z `ArchivedFile`, czyli są liczone
     * NAD ZAPISANYMI bajtami, a nie parsowane ponownie — inaczej indeks mógłby opisywać coś innego
     * niż plik w archiwum.
     */
    public function testPrzepisujeTozsamoscZArchiwumIKontekstKonta(): void {
        $archived = new ArchivedFile('67/2026/06/abc.eml', str_repeat('a', 64), 4242);

        $message = $this->buildFrom('simple.eml', archived: $archived, uid: 7);

        $this->assertSame('67/2026/06/abc.eml', $message->getArchivePath());
        $this->assertSame(str_repeat('a', 64), $message->getSha256());
        $this->assertSame(4242, $message->getSize());
        $this->assertSame(7, $message->getImapUid());
        $this->assertSame('INBOX', $message->getFolder(), 'Folder bierze się z konta, nie z maila');
    }

    /**
     * Nagłówek `Message-ID` trafia do bazy BEZ nawiasów kątowych — te są składnią, nie wartością.
     */
    public function testZdejmujeNawiasyZMessageId(): void {
        $message = $this->buildFrom('simple.eml');

        $this->assertSame('84df8acb-6be1-41a6-8e11-a7151eb385fa@example.com', $message->getMessageId());
    }

    /**
     * webklex zostawia w `personal` cudzysłowy quoted-string z nagłówka `From`. Są delimiterem
     * składni adresu, nie częścią nazwy — zdejmujemy TYLKO zewnętrzną parę, więc przecinek
     * w środku (powód, dla którego nazwa w ogóle jest cytowana) zostaje.
     */
    public function testZdejmujeCudzyslowyZNazwyNadawcy(): void {
        $message = $this->buildFrom('simple.eml');

        $this->assertSame('Kowalska, Anna', $message->getFromName());
        $this->assertSame('anna@example.com', $message->getFromEmail());
    }

    /**
     * Sedno gotchy z CLAUDE.md: bez dekodera `iconv` webklex oddałby surowe `=?UTF-8?B?…?=`
     * i tak trafiłoby to do indeksu — także do wyszukiwarki, gdzie nikt by tego nie znalazł.
     */
    public function testDekodujeNaglowkiZEncodedWords(): void {
        $message = $this->buildFrom('encoded-headers.eml');

        $this->assertSame('Zażółć gęślą jaźń', $message->getSubject());
        $this->assertSame('Zażółć Gęślą', $message->getFromName());
        $this->assertStringNotContainsString('=?UTF-8?', (string) $message->getSubject());
    }

    /**
     * Nagłówek `Date` (deklarowana data wysłania) — nie INTERNALDATE, po którym selekcjonujemy rok.
     * Porównujemy znaczniki czasu, żeby test nie zależał od strefy, w jakiej zwróci go parser.
     */
    public function testCzytaDateZNaglowka(): void {
        $message = $this->buildFrom('simple.eml');

        $this->assertNotNull($message->getDate());
        $this->assertSame(
            (new \DateTimeImmutable('2026-06-16 07:58:58 +0200'))->getTimestamp(),
            $message->getDate()->getTimestamp(),
        );
    }

    /**
     * Mail bez `Date` musi dać `null`, a nie „dzisiaj" — na tym stoi `NULLS LAST` w `searchPage()`
     * (podstawiona data cicho wypchnęłaby taki mail na szczyt listy).
     */
    public function testBrakDatyIMessageIdDajeNull(): void {
        $message = $this->buildFrom('no-date.eml');

        $this->assertNull($message->getDate());
        $this->assertNull($message->getMessageId());
        $this->assertNull($message->getFromName(), 'Adres bez nazwy → fromName null, nie pusty string');
        $this->assertSame('bezdaty@example.com', $message->getFromEmail());
    }

    /**
     * Przy `multipart/alternative` do indeksu idzie część TEKSTOWA — HTML jest tylko fallbackiem.
     * Pełny render (iframe + HTMLPurifier) czyta z `.eml` dopiero w 4.5.
     */
    public function testPreferujeCzescTekstowaNadHtml(): void {
        $message = $this->buildFrom('multipart-alternative.eml');

        $this->assertSame('To jest wersja tekstowa.', $message->getBody());
    }

    public function testBezCzesciTekstowejBierzeHtml(): void {
        $message = $this->buildFrom('html-only.eml');

        $this->assertStringContainsString('Wyłącznie HTML.', (string) $message->getBody());
    }

    /**
     * Załączniki to WYŁĄCZNIE metadane — bajty zostają w `.eml` i nie są duplikowane na dysk.
     * `size` i `sha256` liczymy nad ZDEKODOWANĄ treścią, więc muszą zgadzać się z oryginalnym
     * payloadem, a nie z jego zapisem base64.
     */
    public function testWyciagaMetadaneZalacznika(): void {
        $message = $this->buildFrom('with-attachment.eml');

        $this->assertTrue($message->hasAttachments());
        $this->assertCount(1, $message->getAttachments());

        $attachment = $message->getAttachments()->first();
        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame('faktura.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getMimeType());
        $this->assertSame(strlen(self::ATTACHMENT_PAYLOAD), $attachment->getSize());
        $this->assertSame(hash('sha256', self::ATTACHMENT_PAYLOAD), $attachment->getSha256());
    }

    public function testMailBezZalacznikowMaFlageNaFalse(): void {
        $message = $this->buildFrom('simple.eml');

        $this->assertFalse($message->hasAttachments());
        $this->assertCount(0, $message->getAttachments());
    }

    /**
     * Buduje `Message` z pliku fixtury.
     *
     * @param string            $fixture  Nazwa pliku w `tests/Fixtures/eml`, np. "simple.eml"
     * @param ArchivedFile|null $archived Metadane zapisu; domyślnie policzone z bajtów fixtury
     * @param int               $uid      UID w folderze IMAP, np. 3
     *
     * @return Message Niezapisana encja z dowiązanymi załącznikami
     */
    private function buildFrom(string $fixture, ?ArchivedFile $archived = null, int $uid = 3): Message {
        $raw = $this->rawEml($fixture, $uid);

        $archived ??= new ArchivedFile(
            sprintf('67/2026/06/%s.eml', hash('sha256', $raw->raw)),
            hash('sha256', $raw->raw),
            strlen($raw->raw),
        );

        return $this->factory->fromRaw($this->account(), $raw, $archived);
    }

    /**
     * Czyta plik fixtury jako `RawMessage` (bajty 1:1, bez żadnej normalizacji).
     *
     * @param string $fixture Nazwa pliku, np. "encoded-headers.eml"
     * @param int    $uid     UID w folderze IMAP, np. 3
     *
     * @return RawMessage Surowe źródło gotowe dla fabryki
     */
    private function rawEml(string $fixture, int $uid = 3): RawMessage {
        $path = self::FIXTURE_DIR . '/' . $fixture;
        $this->assertFileExists($path);

        return new RawMessage($uid, (string) file_get_contents($path), new \DateTimeImmutable('2026-06-16 07:58:58 +0200'));
    }

    /**
     * Konto źródłowe z ID (fabryka bierze z niego FK i folder).
     *
     * @return MailAccount Konto #67
     */
    private function account(): MailAccount {
        $account = EntityFactory::account();
        EntityFactory::withId($account, 67);

        return $account;
    }
}
