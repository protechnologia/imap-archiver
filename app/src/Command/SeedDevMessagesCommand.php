<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ArchiveStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Zaślepki wiadomości do oglądania skrzynki w dev (etap 4.4, przebudowane w 4.5).
 *
 * Trzy maile z realnego importu nie wystarczą, żeby zobaczyć paginację, przewijanie kolumny ani
 * zachowanie szukania — a import z prawdziwej skrzynki dla samego wyglądu byłby przesadą.
 *
 * OD 4.5 ZAŚLEPKI MAJĄ PRAWDZIWE PLIKI `.eml`. Wcześniej powstawał sam wpis w indeksie, bo do
 * oglądania listy plik jest zbędny. Podgląd treści czyta jednak wyłącznie z archiwum
 * (`MailBodyReader` — brak pliku to brak treści, bez zapasu z bazy), więc bezplikowe zaślepki
 * byłyby ślepe na całą ścieżkę renderu: nie dałoby się w dev zobaczyć ani przełącznika
 * tekst/HTML, ani sanityzacji, ani sandboksa. Pliki idą przez ten sam `ArchiveStorage`, co import.
 *
 * Wiadomości powstają w TRZECH KSZTAŁTACH (`$number % 3`) i to jest sedno zmiany: sam `text/plain`,
 * sam `text/html` i `multipart/alternative`. Dopiero komplet pokazuje wszystkie stany przełącznika
 * w podglądzie — w tym obie pozycje ZABLOKOWANE, których inaczej nie zobaczysz poza testami.
 *
 * HTML zaślepek jest ZŁOŚLIWY CELOWO: ma `<script>`, piksel śledzący z obcego hosta, styl inline
 * i link, którego napis kłamie o adresie. Dzięki temu w przeglądarce widać, że sanitizer, CSP
 * i `sandbox` faktycznie działają, zamiast wierzyć w to na słowo testów jednostkowych.
 *
 * Komenda odmawia pracy poza `dev` — w `prod` zaśmiecałaby indeks realnego konta.
 */
#[AsCommand(
    name: 'app:dev:seed-messages',
    description: 'Generuje zaślepki wiadomości razem z plikami .eml (tylko dev)',
)]
class SeedDevMessagesCommand extends Command {

    /** Wzorce tematów — `%d` dostaje numer, żeby dało się szukać konkretnej wiadomości. */
    private const SUBJECTS = [
        'Faktura VAT %d/2026',
        'Potwierdzenie zamówienia #%d',
        'Newsletter — wydanie %d',
        'Przypomnienie o płatności (%d)',
        'Raport miesięczny nr %d',
        'Zapytanie ofertowe %d',
        'Umowa do podpisu — wersja %d',
        'Podsumowanie tygodnia %d',
        'Zmiana terminu spotkania (%d)',
        'Dostawa zrealizowana — paczka %d',
    ];

    private const SENDERS = [
        ['Anna Kowalska', 'anna.kowalska@example.com'],
        ['Jan Nowak', 'jan.nowak@firma.example'],
        ['Biuro Obsługi Klienta', 'bok@sklep.example'],
        ['Zespół Księgowości', 'ksiegowosc@example.org'],
        ['Michał Wiśniewski', 'm.wisniewski@example.net'],
    ];

    /** Data zastępcza dla kubełka `rok/mm` w archiwum, gdy mail celowo nie ma nagłówka `Date`. */
    private const FALLBACK_DATE = '2026-06-16 10:00';

    public function __construct(
        private readonly Connection $connection,
        private readonly ArchiveStorage $archive,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'ID konta; domyślnie pierwsze w bazie')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Ile wiadomości wygenerować', '120');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment !== 'dev') {
            $io->error(sprintf('Komenda działa tylko w środowisku dev (jest: %s).', $this->environment));

            return Command::FAILURE;
        }

        $accountId = $this->resolveAccountId($input->getOption('account'));
        if ($accountId === null) {
            $io->error('Nie ma żadnego konta pocztowego — najpierw załóż je w panelu.');

            return Command::FAILURE;
        }

        $count    = max(1, (int) $input->getOption('count'));
        $inserted = 0;

        for ($i = 1; $i <= $count; ++$i) {
            $inserted += $this->seedMessage($accountId, $i) ? 1 : 0;
        }

        $io->success(sprintf('Wstawiono %d z %d wiadomości do konta %d (reszta już istniała).', $inserted, $count, $accountId));

        return Command::SUCCESS;
    }

    /**
     * Zapisuje `.eml` w archiwum i wstawia odpowiadający mu wpis indeksu; duplikaty pomija.
     *
     * Kolejność jest ta sama co w imporcie (`ImportManager`): najpierw plik, potem indeks
     * wyprowadzony z TYCH SAMYCH bajtów, na końcu weryfikacja przez odczyt z dysku. `sha256`
     * i rozmiar biorą się z `ArchivedFile`, nie są zmyślane — dzięki temu zaślepka przechodzi
     * dokładnie tę samą kontrolę, co prawdziwa wiadomość.
     *
     * Generowanie jest DETERMINISTYCZNE (żadnego `random` ani „teraz"), więc te same bajty dają
     * ten sam `sha256` przy każdym uruchomieniu i `ON CONFLICT` załatwia idempotencję.
     *
     * @param int $accountId ID konta, np. 67
     * @param int $number    Numer w serii, np. 42 — wchodzi w temat i różnicuje dane
     *
     * @return bool true gdy wstawiono nową wiadomość, false gdy taka już była
     */
    private function seedMessage(int $accountId, int $number): bool {
        $sentAt   = $this->sentAt($number);
        $raw      = $this->buildEml($number, $sentAt);
        $archived = $this->archive->store($accountId, $sentAt ?? new \DateTimeImmutable(self::FALLBACK_DATE), $raw);

        // Weryfikacja jak w imporcie: odczyt z dysku i przeliczenie sumy. Bez tego `verified`
        // byłoby deklaracją, a nie faktem — a na tej fladze stoi bezpieczne kasowanie z etapu 6.
        $verified = hash('sha256', $this->archive->read($archived->relativePath)) === $archived->sha256;

        [$fromName, $fromEmail] = self::SENDERS[$number % count(self::SENDERS)];

        $id = $this->connection->fetchOne(
            'INSERT INTO message (account_id, folder, message_id, subject, from_name, from_email, sent_at,
                                  size, sha256, has_attachments, verified, body, imap_uid, archive_path)
             VALUES (:account, :folder, :messageId, :subject, :fromName, :fromEmail, :sentAt, :size, :sha256,
                     false, :verified, :body, :imapUid, :archivePath)
             ON CONFLICT (account_id, sha256) DO NOTHING
             RETURNING id',
            [
                'account'     => $accountId,
                'folder'      => 'INBOX',
                'messageId'   => sprintf('dev-seed-%d@example.com', $number),
                'subject'     => sprintf(self::SUBJECTS[$number % count(self::SUBJECTS)], $number),
                'fromName'    => $fromName,
                'fromEmail'   => $fromEmail,
                'sentAt'      => $sentAt?->format('Y-m-d H:i:s'),
                'size'        => $archived->size,
                'sha256'      => $archived->sha256,
                'verified'    => $verified ? 'true' : 'false',
                'body'        => $this->textContent($number),
                'imapUid'     => 10000 + $number,
                'archivePath' => $archived->relativePath,
            ],
        );

        return $id !== false;
    }

    /**
     * Data wysłania zaślepki; co dwunasta jej NIE MA (null).
     *
     * Brak daty jest celowy i sprawdza dwie rzeczy naraz: sortowanie `NULLS LAST` z etapu 4.1
     * oraz pułapkę webkleksa, który przy braku nagłówka `Date` oddaje epokę zamiast null.
     *
     * @param int $number Numer w serii, np. 42
     *
     * @return \DateTimeImmutable|null Data wysłania albo null
     */
    private function sentAt(int $number): ?\DateTimeImmutable {
        if ($number % 12 === 0) {
            return null;
        }

        return (new \DateTimeImmutable(self::FALLBACK_DATE))
            ->modify(sprintf('-%d hours -%d minutes', $number * 7, $number * 13));
    }

    /**
     * Składa surowy `.eml` w jednym z trzech kształtów, zależnie od `$number % 3`.
     *
     * @param int                     $number Numer w serii, np. 42
     * @param \DateTimeImmutable|null $sentAt Data do nagłówka `Date`; null pomija nagłówek
     *
     * @return string Surowe bajty wiadomości (pełne RFC 5322)
     */
    private function buildEml(int $number, ?\DateTimeImmutable $sentAt): string {
        [$contentHeaders, $body] = match ($number % 3) {
            0       => $this->entityText($number),
            1       => $this->entityHtml($number),
            default => $this->entityAlternative($number),
        };

        return $this->headers($number, $sentAt) . $contentHeaders . "\r\n" . $body;
    }

    /**
     * Nagłówki wspólne wszystkich zaślepek (bez `Content-*`).
     *
     * Temat i nazwa nadawcy jadą jako encoded-word RFC 2047, bo zawierają polskie znaki — to
     * jednocześnie sprawdza dekoder `iconv` wymuszony w `MessageFactory` i `MailBodyReader`.
     *
     * @param int                     $number Numer w serii, np. 42
     * @param \DateTimeImmutable|null $sentAt Data do nagłówka `Date`; null pomija nagłówek
     *
     * @return string Blok nagłówków zakończony CRLF, np. "From: …\r\nSubject: …\r\n"
     */
    private function headers(int $number, ?\DateTimeImmutable $sentAt): string {
        [$fromName, $fromEmail] = self::SENDERS[$number % count(self::SENDERS)];
        $subject                = sprintf(self::SUBJECTS[$number % count(self::SUBJECTS)], $number);

        $headers = sprintf("From: %s <%s>\r\n", $this->encodeHeader($fromName), $fromEmail)
            . "To: archiwum@example.com\r\n"
            . sprintf("Subject: %s\r\n", $this->encodeHeader($subject))
            . sprintf("Message-ID: <dev-seed-%d@example.com>\r\n", $number)
            . "MIME-Version: 1.0\r\n";

        if ($sentAt !== null) {
            $headers .= sprintf("Date: %s\r\n", $sentAt->format('D, d M Y H:i:s O'));
        }

        return $headers;
    }

    /**
     * Kształt 1: sama część `text/plain` — przełącznik zablokowany na pozycji „Tekst".
     *
     * @param int $number Numer w serii, np. 42
     *
     * @return array{0: string, 1: string} [nagłówki Content-*, treść]
     */
    private function entityText(int $number): array {
        return [
            "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n",
            $this->base64($this->textContent($number)),
        ];
    }

    /**
     * Kształt 2: sama część `text/html` — przełącznik zablokowany na pozycji „HTML".
     *
     * @param int $number Numer w serii, np. 42
     *
     * @return array{0: string, 1: string} [nagłówki Content-*, treść]
     */
    private function entityHtml(int $number): array {
        return [
            "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n",
            $this->base64($this->htmlContent($number)),
        ];
    }

    /**
     * Kształt 3: `multipart/alternative` — oba warianty, przełącznik w pełni czynny.
     *
     * Granica jest wyprowadzona z numeru, a nie losowa: te same bajty przy każdym przebiegu,
     * więc `sha256` się nie zmienia i idempotencja działa.
     *
     * @param int $number Numer w serii, np. 42
     *
     * @return array{0: string, 1: string} [nagłówki Content-*, treść]
     */
    private function entityAlternative(int $number): array {
        $boundary = sprintf('----dev-seed-%d', $number);

        $body = sprintf("--%s\r\n", $boundary)
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $this->base64($this->textContent($number)) . "\r\n"
            . sprintf("--%s\r\n", $boundary)
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $this->base64($this->htmlContent($number)) . "\r\n"
            . sprintf("--%s--\r\n", $boundary);

        return [sprintf("Content-Type: multipart/alternative; boundary=\"%s\"\r\n", $boundary), $body];
    }

    /**
     * Treść tekstowa zaślepki — zawiera goły adres, czyli to, czym wersja tekstowa bije HTML.
     *
     * @param int $number Numer w serii, np. 42
     *
     * @return string Treść `text/plain`
     */
    private function textContent(int $number): string {
        return sprintf(
            "Dzień dobry,\r\n\r\n"
            . "w załączeniu przesyłam dokument numer %d do akceptacji.\r\n"
            . "Szczegóły są dostępne pod adresem: https://phishing.example/faktura/%d\r\n\r\n"
            . "Pozdrawiam,\r\n"
            . "Zespół testowy (wiadomość wygenerowana lokalnie przez app:dev:seed-messages)\r\n",
            $number,
            $number,
        );
    }

    /**
     * Treść HTML zaślepki — CELOWO z ładunkiem, żeby w przeglądarce było widać działanie zapór.
     *
     * Każdy element ma swojego adresata: `<script>` i `onerror` sprawdzają sanitizer, obraz
     * z obcego hosta sprawdza `allowed_media_hosts: []` oraz CSP, `style` sprawdza
     * `drop_attributes`, a link z kłamliwym napisem pokazuje wektor, przed którym NIE broni
     * żadna z trzech warstw — i dla którego istnieje przełącznik na wersję tekstową.
     *
     * @param int $number Numer w serii, np. 42
     *
     * @return string Treść `text/html`
     */
    private function htmlContent(int $number): string {
        return sprintf(
            '<html><body style="background:#fff">'
            . '<script>document.title = "sanitizer nie zadziałał";</script>'
            . '<h1 style="color:#c00">Dokument numer %d</h1>'
            . '<p>Dzień dobry, w załączeniu przesyłamy <strong>dokument numer %d</strong> do akceptacji.</p>'
            . '<table border="1" cellpadding="4"><tr><th>Pozycja</th><th>Kwota</th></tr>'
            . '<tr><td>Usługa archiwizacji</td><td>1 230,00 zł</td></tr>'
            . '<tr><td>Wsparcie techniczne</td><td>410,00 zł</td></tr></table>'
            . '<p><a href="https://phishing.example/faktura/%d">https://bank.example/platnosci</a></p>'
            . '<img src="https://tracking.example/pixel.gif?id=%d" width="1" height="1" alt="">'
            . '<img src="https://tracking.example/logo.png" onerror="alert(1)" alt="Logo nadawcy">'
            . '</body></html>',
            $number,
            $number,
            $number,
            $number,
        );
    }

    /**
     * Koduje wartość nagłówka jako encoded-word RFC 2047 (base64, UTF-8).
     *
     * @param string $value Wartość, np. "Zespół Księgowości"
     *
     * @return string Zakodowany nagłówek, np. "=?UTF-8?B?WmVzcMOzxYIg…?="
     */
    private function encodeHeader(string $value): string {
        return sprintf('=?UTF-8?B?%s?=', base64_encode($value));
    }

    /**
     * Koduje treść części MIME jako base64 łamane po 76 znakach (RFC 2045).
     *
     * @param string $content Treść części, np. "Dzień dobry,…"
     *
     * @return string Zakodowana treść z łamaniem wierszy
     */
    private function base64(string $content): string {
        return chunk_split(base64_encode($content), 76, "\r\n");
    }

    /**
     * Konto z opcji albo pierwsze z bazy.
     *
     * @param mixed $option Wartość `--account` albo null
     *
     * @return int|null ID konta, np. 67; null gdy w bazie nie ma żadnego
     */
    private function resolveAccountId(mixed $option): ?int {
        if ($option !== null) {
            return (int) $option;
        }

        $accountId = $this->connection->fetchOne('SELECT id FROM mail_account ORDER BY id LIMIT 1');

        return $accountId === false ? null : (int) $accountId;
    }
}
