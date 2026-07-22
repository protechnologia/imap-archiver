<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailAccount;
use App\Model\FetchResult;
use App\Model\RawMessage;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

/**
 * Warstwa dostępu do IMAP — cała mechanika czytania skrzynki w jednym miejscu (etap 3.3).
 *
 * Zarządza PEŁNYM cyklem połączenia (connect → EXAMINE → SEARCH → BODY.PEEK[] → disconnect) i
 * enkapsuluje wszystkie dziwactwa webklex (pusty SEARCH, matcher pojedynczego fetcha, klucz
 * `BODY[]`, `ImapProtocol` zamiast interfejsu, parsowanie INTERNALDATE). Koordynator (`ImportManager`)
 * dostaje tylko `FetchResult`-y (sukces/porażka per mail) i nie wie nic o protokole.
 *
 * Bezstanowy — gotowy na worker mode (etap 4): `readYear()` otwiera i zamyka własne połączenie,
 * nie trzyma stanu między wywołaniami.
 *
 * READ-ONLY twardo: folder otwierany przez EXAMINE (serwer odrzuca STORE), treść wyłącznie przez
 * `BODY.PEEK[]` (nie ustawia `\Seen`), selekcja bez pobierania body — nigdy nie zmienia flag, nic
 * nie kasuje. Pełne „dlaczego" w README → „Ciekawostki techniczne".
 */
final class ImapReader {

    public function __construct(
        private readonly ImapConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * Iteruje read-only wiadomości z danego roku jako leniwy generator `FetchResult`.
     *
     * Zarządza całym cyklem połączenia (connect → EXAMINE → SEARCH po INTERNALDATE → BODY.PEEK[]
     * per UID → disconnect w `finally`). Generator jest LENIWY: połączenie otwiera się przy pierwszej
     * iteracji i trzyma otwarte przez całą pętlę (jedno połączenie na rok), a zamyka po jej końcu.
     *
     * Dwie klasy błędów, celowo różnie traktowane:
     *  - „setup" (brak folderu, złe połączenie) → RZUCA wyjątkiem i przerywa — fatalne, nic nie da
     *     się przeczytać;
     *  - fetch JEDNEGO maila → oddawany jako `FetchResult::failure(...)` (wartość, nie wyjątek), więc
     *     jeden zepsuty mail nie przerywa rocznika (generatora po wyjątku nie da się wznowić).
     *
     * Co zrobić z każdym wynikiem (zapis, liczenie) należy do konsumenta (`ImportManager`).
     *
     * @param MailAccount $account Konto źródłowe
     * @param int         $year    Rok, np. 2025
     *
     * @return iterable<int, FetchResult> Kolejne wyniki pobrania (leniwie): sukces lub porażka per UID
     *
     * @throws \RuntimeException                                     gdy folder nie istnieje / złe połączenie (setup)
     * @throws \Webklex\PHPIMAP\Exceptions\ConnectionFailedException gdy połączenie/logowanie zawiedzie
     */
    public function readYear(MailAccount $account, int $year): iterable {
        $client = $this->connectionFactory->connect($account);

        try {
            $folder = $client->getFolder($account->getFolder());
            if ($folder === null) {
                throw new \RuntimeException(sprintf('Folder "%s" nie istnieje na serwerze.', $account->getFolder()));
            }

            // EXAMINE = otwarcie read-only: serwer odrzuci STORE (zmianę flag). Realna gwarancja
            // „bez \Seen" to i tak niepobieranie body przy selekcji i BODY.PEEK[] przy treści.
            $folder->examine();

            // Surowy fetch() (BODY.PEEK[]) jest tylko w konkretnej ImapProtocol, nie w interfejsie —
            // zawężamy raz, na wejściu.
            $connection = $client->getConnection();
            if (!$connection instanceof ImapProtocol) {
                throw new \RuntimeException('Połączenie nie jest ImapProtocol — surowy FETCH niedostępny.');
            }

            foreach ($this->searchYear($folder, $year) as $uid) {
                yield $this->tryFetch($connection, $uid);
            }
        }
        finally {
            $client->disconnect();
        }
    }

    /**
     * Zwraca UID-y wiadomości z danego roku (po INTERNALDATE), rosnąco. Read-only.
     *
     * SEARCH `SINCE 01-Jan-<rok>` … `BEFORE 01-Jan-<rok+1>` filtruje po INTERNALDATE (data przyjęcia
     * przez serwer), NIE po nagłówku `Date` (to byłoby `SENTSINCE/SENTBEFORE`). Każdy mail ma
     * INTERNALDATE, więc rocz­niki kafelkują skrzynkę bez dziur (patrz README → „Ciekawostki
     * techniczne"). webklex formatuje datę jako `d-M-Y` — granica po dacie serwera, bez czasu/strefy.
     *
     * `setFetchBody(false)`: treści nie pobieramy (nagłówki przez `RFC822.HEADER` = peek), `\Seen`
     * nietknięta. Query nie może być pusty (gotcha o pustym SEARCH) — stąd zawsze `whereSince/Before`;
     * `leaveUnread()` jest tu bez znaczenia (działa dopiero przy pobieraniu body) — zostaje jako
     * intencja i dla spójności z `app:imap:ping`.
     *
     * @param Folder $folder Read-only folder do przeszukania
     * @param int    $year   Rok, np. 2025
     *
     * @return list<int> UID-y rosnąco, np. [3, 7, 12]
     */
    private function searchYear(Folder $folder, int $year): array
    {
        $since  = new \DateTimeImmutable(sprintf('%d-01-01', $year));
        $before = new \DateTimeImmutable(sprintf('%d-01-01', $year + 1));

        $messages = $folder->query()
            ->whereSince($since)
            ->whereBefore($before)
            ->leaveUnread()
            ->setFetchBody(false)
            ->get();

        $uids = array_map(static fn ($message): int => (int) $message->getUid(), $messages->all());
        sort($uids);

        return $uids;
    }

    /**
     * Pobiera jedną wiadomość i pakuje wynik w `FetchResult` — sukces albo porażka, bez wyjątku.
     *
     * Cienka warstwa „error jako wartość" nad `fetchRaw()`: łapie błąd pobrania POJEDYNCZEGO maila
     * i zamienia go na `FetchResult::failure(...)`, żeby jeden zepsuty mail nie przerwał rocznika
     * (błędy „setup" łapie już `readYear`, nie ta metoda).
     *
     * @param ImapProtocol $connection Aktywne połączenie IMAP
     * @param int          $uid        UID wiadomości w folderze
     *
     * @return FetchResult Sukces (`RawMessage`) albo porażka (uid + powód)
     */
    private function tryFetch( ImapProtocol $connection, int $uid ): FetchResult {
        try {
            $raw = $this->fetchRaw($connection, $uid);
            return FetchResult::ok($raw);
        }
        catch( \Throwable $e ) {
            return FetchResult::failure( $uid, $e->getMessage() );
        }
    }

    /**
     * Pobiera bajtowo-wierne źródło `.eml` wiadomości i jej INTERNALDATE. Read-only (PEEK).
     *
     * Woła surowy `UID FETCH <uid> BODY.PEEK[] INTERNALDATE` — `PEEK` nie ustawia `\Seen`. Fetch
     * idzie z WIELOMA itemami i UID-em jako tablicą `[$uid]`, inaczej matcher webklex rzuca „single
     * id was not found" (gotcha spike'u 3.0). Serwer zwraca PEEK pod kluczem `BODY[]`.
     *
     * @param ImapProtocol $connection Aktywne połączenie IMAP (surowy `fetch()` jest tylko tu)
     * @param int          $uid        UID wiadomości w folderze
     *
     * @return RawMessage UID + surowe `.eml` + INTERNALDATE, np.:
     *   RawMessage {
     *       uid:          3,
     *       raw:          "Return-Path: <...>\r\n...\r\n\r\n<treść>",
     *       internalDate: new \DateTimeImmutable('2026-06-16 07:58:58 +0200'),
     *   }
     *
     * @throws \RuntimeException gdy serwer nie zwróci źródła lub INTERNALDATE
     */
    private function fetchRaw(ImapProtocol $connection, int $uid): RawMessage {
        $data = $connection
            ->fetch(['UID', 'BODY.PEEK[]', 'INTERNALDATE'], [$uid], null, IMAP::ST_UID)
            ->validatedData();

        $raw = $data[$uid]['BODY[]'] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('serwer nie zwrócił surowego źródła (BODY[]).');
        }

        $internalDate = $this->parseInternalDate($data[$uid]['INTERNALDATE'] ?? null);
        $rawMessage   = new RawMessage($uid, $raw, $internalDate );

        return $rawMessage;
    }

    /**
     * Parsuje INTERNALDATE z odpowiedzi serwera (np. "16-Jun-2026 07:58:58 +0200").
     *
     * Datę bierzemy ze strefą zwróconą przez serwer — steruje ona kubełkiem `<rok>/<mm>` w archiwum
     * (data przyjęcia przez serwer, nie nagłówek `Date`).
     *
     * @param mixed $value Wartość spod klucza `INTERNALDATE`
     *
     * @throws \RuntimeException gdy brak wartości lub nie da się jej sparsować
     */
    private function parseInternalDate(mixed $value): \DateTimeImmutable {
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('brak INTERNALDATE w odpowiedzi serwera.');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('nieparsowalne INTERNALDATE „%s".', $value), 0, $e);
        }
    }
}
