<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Model\MailBody;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Wyciąga treść wiadomości do podglądu — z `.eml`, nie z indeksu (etap 4.5).
 *
 * DLACZEGO NIE `Message.body`: indeks trzyma tylko ZIARNO tekstowe. `MessageFactory::extractBody()`
 * preferuje część `text/plain`, a HTML bierze wyłącznie jako zapas — więc dla maila HTML-only
 * w kolumnie `body` siedzi ŹRÓDŁO HTML-a, które podgląd escapował i pokazywał jako ścianę tagów.
 * Autorytatywne jest to, co leży w `.eml` (patrz docblok encji `Message`), i wyłącznie stamtąd czytamy:
 * brak pliku to brak treści, bez podmieniania jej czymkolwiek z bazy. Indeks da się odbudować
 * z archiwum, nigdy odwrotnie — podgląd nie może sugerować inaczej.
 *
 * Zwraca OBA warianty naraz, choć podgląd rysuje jeden. Powód jest w `MailBody`: przełącznik
 * tekst/HTML musi wiedzieć, czy drugi wariant istnieje, a jedno parsowanie odpowiada na oba
 * pytania — treść i dostępność.
 *
 * TREŚĆ JEST SUROWA. Sanityzacja HTML-a należy do warstwy, która go wypuszcza do przeglądarki
 * (`MailController::body()`); gdyby robił ją czytnik, każdy przyszły konsument `.eml` musiałby
 * pamiętać, czy dostaje bajty zabezpieczone, czy nie.
 *
 * Bezstanowy — gotowy na worker mode (etap 5).
 */
class MailBodyReader {
    /**
     * Konfiguracja webklex identyczna jak w `MessageFactory`: dekoder nagłówków przez `iconv`.
     *
     * Bez `ext-imap` domyślny dekoder nie rozwija encoded-words RFC 2047 — pełne „dlaczego"
     * w docbloku `MessageFactory::$imapConfig`. Tutaj dotyczy to głównie deklaracji `charset`
     * części MIME, od której zależy poprawność polskich znaków w treści.
     */
    private readonly Config $imapConfig;

    /**
     * __construct
     */
    public function __construct(
        private readonly ArchiveStorage $archive,
    ) {
        $this->imapConfig = Config::make(['decoding' => ['options' => ['header' => 'iconv']]]);
    }

    /**
     * Czyta oba warianty treści z `.eml`; przy niedostępnym pliku oddaje pusty `MailBody`.
     *
     * Brak pliku NIE jest wyjątkiem: podgląd ma wtedy powiedzieć „nie ma tego w archiwum",
     * a nie wywalić całą skrzynkę pięćsetką. Rozjazd „rekord w bazie bez pliku" wykrywa
     * `app:doctor` (etap 5.5) — to jego robota, nie kontrolera.
     *
     * @param Message $message Wiadomość z indeksu, np. Message #42
     *
     * @return MailBody Oba warianty treści; każdy bywa null
     */
    public function read(Message $message): MailBody {
        $raw = $this->readRaw($message);
        if ($raw === null) {
            return new MailBody();
        }

        $parsed = ImapMessage::fromString($raw, $this->imapConfig);

        return new MailBody(
            text: $this->nullIfEmpty((string) $parsed->getTextBody()),
            html: $this->nullIfEmpty((string) $parsed->getHTMLBody()),
        );
    }

    /**
     * Podaje surowe bajty `.eml` albo null, gdy pliku nie da się odczytać.
     *
     * @param Message $message Wiadomość z indeksu, np. Message #42
     *
     * @return string|null Surowe bajty `.eml` albo null przy braku pliku
     */
    private function readRaw(Message $message): ?string {
        try {
            return $this->archive->read($message->getArchivePath());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Zwraca przyciętą wartość albo null, gdy po przycięciu pusta (webklex oddaje "" zamiast null).
     *
     * @param string $value Surowa wartość, np. "" albo "  <p>Cześć</p>  "
     *
     * @return string|null Przycięta wartość lub null, np. "<p>Cześć</p>"
     */
    private function nullIfEmpty(string $value): ?string {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
