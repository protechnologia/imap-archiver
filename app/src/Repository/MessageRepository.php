<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Model\MessageListPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository {
    /**
     * __construct
     */
    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, Message::class);
    }

    /**
     * Czy w archiwum jest już wiadomość o danej treści (idempotencja importu, etap 3.3c).
     *
     * Pyta po `UNIQUE(account_id, sha256)` — tożsamością treści jest `sha256` surowego `.eml`
     * (patrz docblok `Message`), nie `messageId` (bywa NULL). Te same bajty na tym samym koncie
     * = ten sam wpis: ponowny import ich nie duplikuje, tylko pomija. Odpytujemy per mail w pętli
     * importu, więc jawnie `SELECT 1 … LIMIT 1` zamiast pełnej hydratacji encji.
     *
     * @param int    $accountId ID konta MailAccount, np. 67
     * @param string $sha256    SHA-256 (hex) surowego `.eml`, np. "6f041317c753…e1"
     *
     * @return bool true, gdy wiadomość o tej treści już istnieje na tym koncie
     */
    public function existsForContent(int $accountId, string $sha256): bool {
        return $this->createQueryBuilder('m')
            ->select('1')
            ->where('m.account = :account')
            ->andWhere('m.sha256 = :sha256')
            ->setParameter('account', $accountId)
            ->setParameter('sha256', $sha256)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * Zwraca stronę listy wiadomości dla podanych kont, opcjonalnie zawężoną frazą (etap 4.1).
     *
     * To JEDYNY szew między UI a wyszukiwaniem: w etapie 7 podmieniamy wnętrze na Meilisearch,
     * nie ruszając kontrolera ani komponentu. Stąd paginacja OFFSETOWA, choć keyset byłby
     * wydajniejszy — silnik wyszukiwania zwraca trafienia + łączną liczbę i sortuje po trafności,
     * do czego keyset po `(sent_at, id)` się nie przekłada.
     *
     * Świadomie NIE MA trybu „wszystkie konta": listę kont podaje warstwa wyżej (`MessageVoter`
     * i kontroler, etap 4.2), więc zapomniany parametr daje pustą stronę, a nie cudzą pocztę.
     *
     * Sortowanie to `sent_at DESC NULLS LAST, id DESC`. Oba człony są konieczne: `sent_at` jest
     * nullable (nieparsowalny nagłówek `Date`), a Postgres przy `DESC` stawia NULL-e NA POCZĄTKU,
     * więc maile bez daty przykryłyby najnowsze; `id` daje deterministyczny tie-break, bez którego
     * paginacja offsetowa potrafi gubić i dublować rekordy między stronami.
     *
     * @param list<int>   $accountIds ID kont, do których użytkownik ma dostęp, np. [67, 68]
     * @param string|null $query      Fraza filtrująca (temat/nadawca/treść) albo null, np. "faktura"
     * @param int         $page       Numer strony od 1; poza zakresem zostaje przycięty, np. 3
     * @param int         $perPage    Rozmiar strony, przycinany do 1..200, np. 50
     *
     * @return MessageListPage Strona wyników wraz z licznikami, np. 50 z 1284 trafień
     */
    public function searchPage(array $accountIds, ?string $query, int $page, int $perPage): MessageListPage {
        // Rozmiar strony pochodzi z żądania (LiveProp) — przycinamy, żeby nikt nie zamówił 100k rekordów.
        $perPage = max(1, min(200, $perPage));

        // Brak kont = brak dostępu do czegokolwiek; nie pytamy bazy (`IN ()` i tak byłoby błędem).
        if ($accountIds === []) {
            return new MessageListPage([], 0, 1, $perPage, 1);
        }

        // Najpierw licznik: potrzebny i do paginacji w UI, i do przycięcia numeru strony niżej.
        $total = (int) $this->searchQueryBuilder($accountIds, $query)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Strona poza zakresem (np. filtr obciął wyniki pod stopami) → oddajemy ostatnią, nie pustkę.
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($pages, $page));

        // Ta sama baza warunków co w `COUNT`, tylko z sortowaniem i oknem `LIMIT/OFFSET`.
        $items = $this->searchQueryBuilder($accountIds, $query)
            ->select('m')
            // NULLS LAST bez natywnego SQL-a: sztuczna kolumna 0/1 sortowana rosnąco przed datą.
            // `HIDDEN` = bierze udział w ORDER BY, ale nie trafia do wyniku (zostają same encje).
            ->addSelect('CASE WHEN m.date IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->orderBy('undated', 'ASC')
            ->addOrderBy('m.date', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return new MessageListPage($items, $total, $page, $perPage, $pages);
    }

    /**
     * Wspólne WHERE wyszukiwania: zawężenie do kont + opcjonalny filtr frazą.
     *
     * Wydzielone, bo `searchPage()` puszcza je dwa razy (raz `COUNT`, raz strona) i warunki
     * MUSZĄ być identyczne — inaczej licznik stron rozjedzie się z zawartością.
     *
     * Filtr działa na `LIKE '%…%'` po czterech kolumnach; po `body` (TEXT) nie użyje żadnego
     * indeksu i przy dużym archiwum będzie wolny — to znany dług, który spłaca etap 7.
     *
     * @param list<int>   $accountIds ID kont, np. [67]
     * @param string|null $query      Fraza albo null, np. "faktura"
     *
     * @return QueryBuilder Builder bez SELECT-a i sortowania (dokłada je wołający)
     */
    private function searchQueryBuilder(array $accountIds, ?string $query): QueryBuilder {
        $qb = $this->createQueryBuilder('m')
            ->where('m.account IN (:accounts)')
            ->setParameter('accounts', $accountIds);

        $query = trim((string) $query);
        if ($query === '') {
            return $qb;
        }

        // Neutralizacja wieloznaczników z frazy użytkownika: bez tego samo „%" wpisane
        // w wyszukiwarkę wybrałoby całe archiwum, a „_" byłby dziką kartą. Znak ucieczki `!`
        // MUSI być ten sam tutaj i w `ESCAPE '!'` niżej — dlatego stoją obok siebie.
        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query));

        // DQL nie zna `ILIKE`, a postgresowy `LIKE` rozróżnia wielkość liter — stąd `LOWER()`
        // po obu stronach porównania.
        $qb->andWhere($qb->expr()->orX(
            "LOWER(m.subject) LIKE :q ESCAPE '!'",
            "LOWER(m.fromName) LIKE :q ESCAPE '!'",
            "LOWER(m.fromEmail) LIKE :q ESCAPE '!'",
            "LOWER(m.body) LIKE :q ESCAPE '!'",
        ))->setParameter('q', '%' . $needle . '%');

        return $qb;
    }

}
