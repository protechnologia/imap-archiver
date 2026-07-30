<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\MailAccount;
use App\Entity\Message;
use App\Model\ArchivedFile;
use App\Model\RawMessage;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Wyprowadza indeks (`Message` + `Attachment`) z surowych bajtów `.eml`. Etap 3.3c.
 *
 * Wszystkie pola do podglądu/szukania parsujemy przez `ImapMessage::fromString($raw)` na DOKŁADNIE
 * tych bajtach, które trafiły do archiwum — DB odzwierciedla to, co leży w `.eml` (decyzja spike'u
 * 3.0). Tożsamość, adres i rozmiar bierzemy z `ArchivedFile` (policzone nad zapisanymi bajtami),
 * a nie liczymy ponownie — jedno źródło prawdy dla `sha256`/`size`/`archivePath`.
 *
 * Czysty mapper: buduje graf encji i go zwraca, nie dotyka `EntityManager` ani IMAP — persist/flush
 * i weryfikacja należą do koordynatora (`ImportManager`). Bezstanowy — gotowy na worker mode (etap 4).
 */
final class MessageFactory {
    /**
     * Konfiguracja webklex dla parsowania ze stringa: dekoder nagłówków przez `iconv`.
     *
     * DLACZEGO to konieczne: bez `ext-imap` (świadomie odrzucone — patrz Stack w CLAUDE.md) domyślny
     * dekoder `utf-8` webklex NIE dekoduje encoded-words RFC 2047 — jego `mimeHeaderDecode()` oddaje
     * surowy tekst, który jako niepusty zwiera obwód przed fallbackiem. Skutek: temat/nazwa nadawcy
     * lądują jako `=?UTF-8?B?…?=`. Tryb `iconv` woła wprost `iconv_mime_decode()` (ext-iconv jest w
     * buildzie PHP) i dekoduje spójnie wszystkie nagłówki (temat i personal w adresach dzielą tę ścieżkę).
     */
    private readonly Config $imapConfig;

    /**
     * __construct
     */
    public function __construct() {
        $this->imapConfig = Config::make(['decoding' => ['options' => ['header' => 'iconv']]]);
    }

    /**
     * Składa `Message` (z załącznikami) z surowego `.eml` i metadanych zapisu do archiwum.
     *
     * @param MailAccount  $account  Konto źródłowe (FK + folder), np. MailAccount #67
     * @param RawMessage   $raw      Surowe źródło z IMAP (bajty + UID; INTERNALDATE steruje kubełkiem)
     * @param ArchivedFile $archived Wynik zapisu: ścieżka względna + `sha256` + rozmiar (nad zapisanymi bajtami)
     *
     * @return Message Niezapisana encja (bez ID) z dowiązanymi `Attachment`, np. "Faktura 06/2026"
     */
    public function fromRaw(MailAccount $account, RawMessage $raw, ArchivedFile $archived): Message {
        $parsed = ImapMessage::fromString($raw->raw, $this->imapConfig);

        [$fromName, $fromEmail] = $this->extractFrom($parsed);

        $message = new Message();
        $message->setAccount($account);
        $message->setFolder($account->getFolder());
        $message->setMessageId($this->extractMessageId($parsed));
        $message->setSubject($this->nullIfEmpty((string) $parsed->getSubject()));
        $message->setFromName($fromName);
        $message->setFromEmail($fromEmail);
        $message->setDate($this->extractDate($parsed));
        $message->setBody($this->extractBody($parsed));

        // Tożsamość/adres/rozmiar — z ArchivedFile (policzone nad zapisanymi bajtami), nie liczymy ponownie.
        $message->setSha256($archived->sha256);
        $message->setSize($archived->size);
        $message->setArchivePath($archived->relativePath);
        $message->setImapUid($raw->uid);

        $attachments = $parsed->getAttachments();
        $message->setHasAttachments($attachments->count() > 0);
        foreach ($attachments as $attachment) {
            $message->addAttachment($this->buildAttachment($attachment));
        }

        return $message;
    }

    /**
     * Wyciąga `Message-ID` bez nawiasów kątowych (`<…>`); null, gdy mail go nie miał.
     *
     * @param ImapMessage $parsed Sparsowana wiadomość webklex
     *
     * @return string|null Message-ID, np. "84df8acb-6be1-41a6-8e11-a7151eb385fa@example.com"
     */
    private function extractMessageId(ImapMessage $parsed): ?string {
        return $this->nullIfEmpty(trim((string) $parsed->getMessageId(), " \t<>"));
    }

    /**
     * Wyciąga nazwę i adres nadawcy z pierwszego wpisu nagłówka `From`.
     *
     * @param ImapMessage $parsed Sparsowana wiadomość webklex
     *
     * @return array{0: ?string, 1: ?string} [nazwa, adres], np. ["Jan Kowalski", "jan.kowalski@example.com"]
     */
    private function extractFrom(ImapMessage $parsed): array {
        $address = $parsed->getFrom()->first();
        if (!$address instanceof Address) {
            return [null, null];
        }

        return [$this->cleanPersonal($address->personal), $this->nullIfEmpty($address->mail)];
    }

    /**
     * Czyści nazwę nadawcy: zdejmuje otaczającą parę cudzysłowów (składnia quoted-string RFC 5322).
     *
     * webklex oddaje `personal` z zachowanym `"…"` z nagłówka `From` (np. `"Adam Kowalski (eGIE)"`) —
     * cudzysłowy są DELIMITEREM składni adresu, nie częścią nazwy. Zdejmujemy tylko dopasowaną parę
     * zewnętrzną (raz), więc cudzysłów wewnątrz nazwy zostaje nietknięty. Potem `nullIfEmpty` (trim).
     *
     * @param string $personal Nazwa z webklex, np. "\"Adam Kowalski (eGIE)\""
     *
     * @return string|null Oczyszczona nazwa lub null, np. "Adam Kowalski (eGIE)"
     */
    private function cleanPersonal(string $personal): ?string {
        $trimmed = trim($personal);
        if (strlen($trimmed) >= 2 && str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            $trimmed = substr($trimmed, 1, -1);
        }

        return $this->nullIfEmpty($trimmed);
    }

    /**
     * Wyciąga datę z nagłówka `Date` jako `DateTimeImmutable`; null, gdy brak lub nieparsowalna.
     *
     * To nagłówek `Date` (nie INTERNALDATE, po którym selekcjonujemy rok) — rozjazd tylko raportujemy,
     * nic po nim nie odrzucamy (patrz gotcha SINCE/BEFORE w CLAUDE.md).
     *
     * PUŁAPKA: przy braku nagłówka `Date` webklex NIE oddaje null. `Attribute::first()` zwraca pusty
     * string (atrybut istnieje, tylko jest pusty), a `toDate()` na pustej wartości daje Carbona
     * z epoki — mail bez daty dostałby w indeksie „1970-01-01 00:00" udające prawdziwą datę wysłania.
     * Stąd test pustki na wartości, nie na `null`.
     *
     * @param ImapMessage $parsed Sparsowana wiadomość webklex
     *
     * @return \DateTimeImmutable|null Data, np. new \DateTimeImmutable('2026-06-16 07:58:58')
     */
    private function extractDate(ImapMessage $parsed): ?\DateTimeImmutable {
        if (trim((string) $parsed->getDate()->first()) === '') {
            return null;
        }

        try {
            return \DateTimeImmutable::createFromInterface($parsed->getDate()->toDate());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Wyciąga treść do podglądu/szukania: preferuje część tekstową, w razie braku HTML; null, gdy pusto.
     *
     * Surowy render (sandbox iframe + HTMLPurifier) należy do podglądu (etap 5) — tu składujemy tylko
     * ziarno tekstowe indeksu.
     *
     * @param ImapMessage $parsed Sparsowana wiadomość webklex
     *
     * @return string|null Treść, np. "Cześć, w załączniku przesyłam fakturę…"
     */
    private function extractBody(ImapMessage $parsed): ?string {
        $text = $this->nullIfEmpty($parsed->getTextBody());

        return $text ?? $this->nullIfEmpty($parsed->getHTMLBody());
    }

    /**
     * Buduje metadane jednego załącznika (bajty zostają w `.eml`, nie duplikujemy na dysk).
     *
     * `sha256` i `size` liczymy nad ZDEKODOWANĄ treścią (to samo źródło dla obu pól). Typ MIME bierzemy
     * z zadeklarowanego `Content-Type` części, a nie ze zgadywania po bajtach.
     *
     * @param \Webklex\PHPIMAP\Attachment $attachment Załącznik webklex
     *
     * @return Attachment Encja metadanych, np. Attachment("faktura.pdf", "application/pdf", 84210)
     */
    private function buildAttachment(\Webklex\PHPIMAP\Attachment $attachment): Attachment {
        $content = (string) $attachment->getContent();

        $entity = new Attachment();
        $entity->setFilename($this->nullIfEmpty((string) $attachment->getName()));
        $entity->setMimeType($this->nullIfEmpty((string) $attachment->getContentType()));
        $entity->setSize(strlen($content));
        $entity->setSha256(hash('sha256', $content));

        return $entity;
    }

    /**
     * Zwraca przyciętą wartość albo null, gdy po przycięciu pusta (webklex oddaje "" zamiast null).
     *
     * @param string $value Surowa wartość, np. "" albo "  Faktura  "
     *
     * @return string|null Przycięta wartość lub null, np. "Faktura"
     */
    private function nullIfEmpty(string $value): ?string {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
