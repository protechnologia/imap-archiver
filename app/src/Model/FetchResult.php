<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Wynik pobrania JEDNEJ wiadomości z IMAP: sukces (`RawMessage`) albo porażka (powód). Etap 3.3.
 *
 * Wzorzec Result/Either — pozwala `ImapReader` oddać błąd pobrania pojedynczego maila jako WARTOŚĆ
 * w strumieniu (a nie wyjątek), dzięki czemu jeden zepsuty fetch nie przerywa całego rocznika
 * (generatora po wyjątku nie da się wznowić). Błędy „setup" (brak folderu, złe połączenie) NIE idą
 * tędy — tam reader dalej rzuca wyjątkiem, bo są fatalne.
 *
 * Niezmiennik (gwarantowany prywatnym konstruktorem + fabrykami): `uid` zawsze obecny, a `message`
 * i `error` wykluczają się — dokładnie jedno jest nie-null.
 *
 * Przykładowe stany:
 *   FetchResult::ok(...):      { uid: 3, message: RawMessage{…}, error: null }
 *   FetchResult::failure(7,…): { uid: 7, message: null, error: "serwer nie zwrócił źródła (BODY[])" }
 */
final readonly class FetchResult {

    private function __construct(
        /** UID wiadomości w folderze IMAP, np. 3. Obecny zawsze — także przy porażce. */
        public int $uid,

        /** Pobrana wiadomość przy sukcesie; `null` przy porażce. */
        public ?RawMessage $message,

        /** Powód porażki przy błędzie; `null` przy sukcesie. */
        public ?string $error,
    ) {
    }

    /**
     * Sukces — niesie pobraną wiadomość.
     *
     * @param RawMessage $message Pobrana wiadomość
     */
    public static function ok(RawMessage $message): self {
        return new self($message->uid, $message, null);
    }

    /**
     * Porażka pobrania jednego maila — niesie UID i powód.
     *
     * @param int    $uid   UID, którego nie udało się pobrać
     * @param string $error Powód (komunikat)
     */
    public static function failure(int $uid, string $error): self {
        return new self($uid, null, $error);
    }

    /**
     * Czy pobranie się powiodło (a więc `message` jest dostępne).
     */
    public function isOk(): bool {
        return $this->message !== null;
    }
}