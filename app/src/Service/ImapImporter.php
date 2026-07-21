<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MailAccount;
use App\Model\ImportSummary;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Pipeline importu rocznika do archiwum (etap 3.3).
 *
 * Docelowo: SEARCH po roku (INTERNALDATE) → pobranie surowego `.eml` (`BODY.PEEK[]`) →
 * `sha256` → idempotencja → zapis do archiwum → indeks `Message`/`Attachment` → weryfikacja
 * (`verified`). Read-only wobec skrzynki: EXAMINE + PEEK, nigdy nie zmienia flag, nic nie kasuje.
 *
 * Bezstanowy — gotowy na worker mode (etap 4): każde `import()` otwiera i zamyka własne
 * połączenie, nie trzyma stanu między wywołaniami.
 *
 * PODETAP 3.3a: implementuje wyłącznie SELEKCJĘ — liczy kandydatów z danego roku. NIE pobiera
 * treści, NIE zapisuje plików ani encji. Pobranie `.eml` (3.3b) i indeks + weryfikacja (3.3c)
 * dochodzą w kolejnych podetapach.
 */
final class ImapImporter {
    
    public function __construct(
        private readonly ImapConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * Importuje wiadomości z danego roku (po INTERNALDATE) z folderu konta.
     *
     * @param MailAccount $account Konto źródłowe, np. MailAccount #67
     * @param int         $year    Rok, np. 2025
     * @param bool        $dryRun  Przebieg próbny (w 3.3a i tak nic nie zapisuje)
     *
     * @return ImportSummary Podsumowanie przebiegu
     *
     * @throws \RuntimeException                                        gdy folder nie istnieje
     * @throws \Webklex\PHPIMAP\Exceptions\ConnectionFailedException    gdy połączenie/logowanie zawiedzie
     */
    public function import(MailAccount $account, int $year, bool $dryRun): ImportSummary {
        $client = $this->connectionFactory->connect($account);

        try {
            $folder = $this->openFolderReadOnly( $client, $account->getFolder() );
            $uids   = $this->searchYear( $folder, $year );

            // 3.3b/3.3c: pętla po $uids — pobranie BODY.PEEK[] + INTERNALDATE → sha256 →
            // idempotencja → ArchiveStorage::store() → indeks Message/Attachment → weryfikacja.
            return new ImportSummary(
                year:       $year,
                candidates: \count($uids),
                dryRun:     $dryRun,
            );
        }
        finally {
            $client->disconnect();
        }
    }

    /**
     * Otwiera folder konta w trybie tylko-do-odczytu (EXAMINE) i go zwraca.
     *
     * `examine()` wysyła IMAP EXAMINE — protokołowy odpowiednik SELECT, ale serwer odrzuci każdy
     * STORE (zmianę flag \Seen/\Deleted) i nie zdejmuje \Recent. To drugi zamek na read-only:
     * właściwą gwarancją „bez \Seen" jest niepobieranie body (setFetchBody(false) w searchYear;
     * BODY.PEEK[] w 3.3b), nie samo EXAMINE.
     *
     * @param Client $client     Połączony klient IMAP
     * @param string $folderPath Ścieżka folderu, np. "INBOX"
     *
     * @return Folder Wybrany, read-only folder
     *
     * @throws \RuntimeException gdy folder nie istnieje na serwerze
     */
    private function openFolderReadOnly(Client $client, string $folderPath): Folder {
        $folder = $client->getFolder($folderPath);
        if ($folder === null) {
            throw new \RuntimeException(sprintf('Folder "%s" nie istnieje na serwerze.', $folderPath));
        }

        $folder->examine();

        return $folder;
    }

    /**
     * Zwraca UID-y wiadomości z danego roku (po INTERNALDATE), rosnąco. Read-only.
     *
     * Zakres roku: SEARCH `SINCE 01-Jan-<rok>` … `BEFORE 01-Jan-<rok+1>`. Filtr idzie po INTERNALDATE
     * (data przyjęcia przez serwer), a NIE po nagłówku `Date` (to byłoby `SENTSINCE/SENTBEFORE`).
     * Dzięki temu każdy mail — INTERNALDATE ma zawsze — trafia do dokładnie jednego rocznika, bez
     * dziur na granicy roku (patrz README → „Ciekawostki techniczne"). webklex formatuje datę jako
     * `d-M-Y`, więc granica idzie po dacie serwera, bez czasu i strefy.
     *
     * Read-only zapewnia `setFetchBody(false)`: treści nie pobieramy, więc `\Seen` zostaje nietknięta
     * (nagłówki lecą przez `RFC822.HEADER` = peek). Surowe źródło pobierzemy dopiero w 3.3b, per-UID,
     * przez `BODY.PEEK[]`.
     *
     * Dwa detale webklex: query nie może być pusty (inaczej serwer zwraca `BAD` — gotcha o pustym
     * SEARCH), stąd zawsze `whereSince/whereBefore`; `leaveUnread()` jest tu bez znaczenia (działa
     * dopiero przy pobieraniu body) — zostaje jako intencja i dla spójności z `app:imap:ping`.
     *
     * @param Folder $folder Read-only folder do przeszukania
     * @param int    $year   Rok, np. 2025
     *
     * @return list<int> UID-y rosnąco, np. [3, 7, 12]
     */
    private function searchYear(Folder $folder, int $year): array {
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
}
