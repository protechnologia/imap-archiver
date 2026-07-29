<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Model\FetchResult;
use App\Model\RawMessage;
use App\Repository\MessageRepository;
use App\Service\ArchiveStorage;
use App\Service\ImapReader;
use App\Service\ImportManager;
use App\Service\MessageFactory;
use App\Tests\Support\Fixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `ImportManager` — polityka importu (etap 3.3). NAJWAŻNIEJSZY test w projekcie.
 *
 * Tu błąd nie oznacza brzydkiego widoku, tylko utratę poczty: zdublowany import, plik bez wpisu
 * w indeksie, wpis bez pliku albo `verified=true` postawione na wiadomości, której nie da się
 * odtworzyć z archiwum. Ostatnie jest najgroźniejsze, bo `verified` jest warunkiem koniecznym
 * kasowania z serwera (etap 6).
 *
 * Konfiguracja testu: PRAWDZIWA baza, PRAWDZIWY `ArchiveStorage` na katalogu tymczasowym
 * i PRAWDZIWY `MessageFactory` — podstawiony jest wyłącznie `ImapReader`, czyli jedyne wejście
 * sieciowe. Dzięki temu test przechodzi całą drogę bajtów: IMAP → plik → indeks → weryfikacja
 * z dysku. (`ImapReader` nie jest `final` właśnie po to — patrz jego docblok.)
 */
class ImportManagerTest extends KernelTestCase {
    private const YEAR = 2026;

    private EntityManagerInterface $em;
    private MessageRepository $messages;
    private Filesystem $filesystem;
    private string $archiveDir;
    private ArchiveStorage $archiveStorage;

    protected function setUp(): void {
        self::bootKernel();

        $this->em       = self::getContainer()->get(EntityManagerInterface::class);
        $this->messages = self::getContainer()->get(MessageRepository::class);

        $this->filesystem = new Filesystem();
        $this->archiveDir = sys_get_temp_dir() . '/imap-archiver-import-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->archiveDir);

        $this->archiveStorage = new ArchiveStorage($this->archiveDir, $this->filesystem);
    }

    protected function tearDown(): void {
        $this->filesystem->remove($this->archiveDir);
    }

    /**
     * Ścieżka szczęśliwa w całości: plik trafia do archiwum, indeks powstaje z TYCH SAMYCH bajtów,
     * a weryfikacja (odczyt z dysku + przeliczony `sha256`) stawia `verified`.
     */
    public function testImportZapisujePlikIndeksIOznaczaWiadomoscJakoZweryfikowana(): void {
        $account = $this->givenAccount();
        $raw     = $this->rawEml('Faktura VAT 12/2026');

        $summary = $this->importFrom($account, [FetchResult::ok($raw)]);

        $this->assertSame(1, $summary->candidates);
        $this->assertSame(1, $summary->imported);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(1, $summary->verified);
        $this->assertSame([], $summary->errors);

        $message = $this->onlyMessageOf($account);
        $this->assertSame('Faktura VAT 12/2026', $message->getSubject());
        $this->assertSame(hash('sha256', $raw->raw), $message->getSha256());
        $this->assertTrue($message->isVerified());

        $sciezka = $this->archiveDir . '/' . $message->getArchivePath();
        $this->assertFileExists($sciezka);
        $this->assertStringEqualsFile($sciezka, $raw->raw, 'Plik w archiwum musi być bajtowo wierny');
    }

    /**
     * Idempotencja po TREŚCI (`UNIQUE(account_id, sha256)`), nie po `Message-ID`. Ponowny przebieg
     * tego samego rocznika ma pominąć wszystko, co już jest — inaczej każdy restart importu
     * dublowałby archiwum.
     */
    public function testPonownyImportTychSamychBajtowNiczegoNieDuplikuje(): void {
        $account = $this->givenAccount();
        $raw     = $this->rawEml('Ta sama wiadomość');

        $this->importFrom($account, [FetchResult::ok($raw)]);
        $summary = $this->importFrom($account, [FetchResult::ok($raw)]);

        $this->assertSame(1, $summary->candidates);
        $this->assertSame(0, $summary->imported);
        $this->assertSame(1, $summary->skipped);
        $this->assertCount(1, $this->messagesOf($account));
    }

    /**
     * `--dry-run` liczy, ile BY zaimportowano, ale nie ma prawa zostawić po sobie ani wiersza
     * w bazie, ani pliku na dysku. Weryfikacja jest wtedy niemożliwa, więc `verified` zostaje 0.
     */
    public function testDryRunNiczegoNieZapisuje(): void {
        $account = $this->givenAccount();
        $raw     = $this->rawEml('Próbny przebieg');

        $summary = $this->importFrom($account, [FetchResult::ok($raw)], dryRun: true);

        $this->assertTrue($summary->dryRun);
        $this->assertSame(1, $summary->imported, 'dry-run liczy, ile BY zaimportowano');
        $this->assertSame(0, $summary->verified);
        $this->assertSame([], $this->messagesOf($account));
        $this->assertSame([], glob($this->archiveDir . '/*') ?: []);
    }

    /**
     * Jeden zepsuty mail nie może przerwać rocznika — porażka pobrania przychodzi z czytnika jako
     * WARTOŚĆ (`FetchResult::failure()`), ląduje w `errors[]`, a reszta jedzie dalej.
     */
    public function testBladPobraniaJednegoMailaNieWywracaPrzebiegu(): void {
        $account = $this->givenAccount();

        $summary = $this->importFrom($account, [
            FetchResult::ok($this->rawEml('Pierwsza', uid: 1)),
            FetchResult::failure(7, 'serwer nie zwrócił źródła (BODY[])'),
            FetchResult::ok($this->rawEml('Trzecia', uid: 12)),
        ]);

        $this->assertSame(3, $summary->candidates);
        $this->assertSame(2, $summary->imported);
        $this->assertSame(2, $summary->verified);
        $this->assertCount(1, $summary->errors);
        $this->assertStringContainsString('UID 7', $summary->errors[0]);
        $this->assertCount(2, $this->messagesOf($account));
    }

    /**
     * Rozjazd pliku z indeksem MUSI zostawić `verified=false` i zgłosić błąd — na tym stoi cały
     * warunek bezpiecznego kasowania z etapu 6.
     *
     * Scenariusz odtwarzamy realnie: podkładamy w archiwum plik o dokładnie tej ścieżce, którą
     * wyliczy `ArchiveStorage` (`<accountId>/<rok>/<mm>/<sha256>.eml`), ale z INNĄ treścią. Zapis
     * jest content-addressed i nie nadpisuje istniejącego pliku, więc na dysku zostaje obca treść,
     * a indeks dostaje `sha256` policzony z pobranych bajtów. Weryfikacja ma to wyłapać.
     */
    public function testRozjazdPlikuZIndeksemNieDajeStatusuZweryfikowana(): void {
        $account = $this->givenAccount();
        $raw     = $this->rawEml('Uszkodzona w archiwum');
        $sciezka = sprintf('%d/2026/06/%s.eml', $account->getId(), hash('sha256', $raw->raw));
        $this->filesystem->dumpFile($this->archiveDir . '/' . $sciezka, 'CAŁKIEM INNE BAJTY');

        $summary = $this->importFrom($account, [FetchResult::ok($raw)]);

        $this->assertSame(1, $summary->imported);
        $this->assertSame(0, $summary->verified, 'Wiadomość bez zgodnego pliku nie może być zweryfikowana');
        $this->assertCount(1, $summary->errors);
        $this->assertStringContainsString('weryfikacja checksumu', $summary->errors[0]);
        $this->assertFalse($this->onlyMessageOf($account)->isVerified());
    }

    /**
     * Puszcza import z podstawionym czytnikiem oddającym z góry ustaloną listę wyników.
     *
     * @param MailAccount       $account Konto źródłowe (już zapisane)
     * @param list<FetchResult> $results Co „zwróci serwer", np. [FetchResult::ok(...)]
     * @param bool              $dryRun  Przebieg próbny
     *
     * @return \App\Model\ImportSummary Podsumowanie przebiegu
     */
    private function importFrom(MailAccount $account, array $results, bool $dryRun = false): \App\Model\ImportSummary {
        $reader = $this->createStub(ImapReader::class);
        $reader->method('readYear')->willReturn($results);

        $manager = new ImportManager(
            $reader,
            $this->archiveStorage,
            new MessageFactory(),
            $this->messages,
            $this->em,
        );

        return $manager->import($account, self::YEAR, $dryRun);
    }

    /**
     * Zapisuje konto, żeby miało ID (kubełek katalogu w archiwum).
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(): MailAccount {
        $account = Fixtures::account();
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Buduje surową wiadomość `.eml` (pełne RFC822) o zadanym temacie.
     *
     * @param string $subject Temat, np. "Faktura VAT 12/2026"
     * @param int    $uid     UID w folderze IMAP, np. 3
     *
     * @return RawMessage Źródło gotowe do podania czytnikowi
     */
    private function rawEml(string $subject, int $uid = 3): RawMessage {
        $raw = implode("\r\n", [
            'Return-Path: <anna@example.com>',
            'From: "Kowalska, Anna" <anna@example.com>',
            'To: biuro@example.com',
            'Subject: ' . $subject,
            'Date: Tue, 16 Jun 2026 07:58:58 +0200',
            'Message-ID: <' . $uid . '.' . md5($subject) . '@example.com>',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Treść wiadomości ' . $subject,
            '',
        ]);

        return new RawMessage($uid, $raw, new \DateTimeImmutable('2026-06-16 07:58:58 +0200'));
    }

    /**
     * Wiadomości zapisane dla konta (świeżo z bazy, bez identity map).
     *
     * @param MailAccount $account Konto źródłowe
     *
     * @return list<Message> Wiadomości w indeksie
     */
    private function messagesOf(MailAccount $account): array {
        $this->em->clear();

        return $this->messages->findBy(['account' => $account->getId()]);
    }

    /**
     * Jedyna wiadomość konta — z asercją, że naprawdę jest dokładnie jedna.
     *
     * @param MailAccount $account Konto źródłowe
     *
     * @return Message Zapisana wiadomość
     */
    private function onlyMessageOf(MailAccount $account): Message {
        $wiadomosci = $this->messagesOf($account);
        $this->assertCount(1, $wiadomosci);

        return $wiadomosci[0];
    }
}
