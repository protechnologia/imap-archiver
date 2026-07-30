<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailAccount>
 */
class MailAccountRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, MailAccount::class);
    }

    /**
     * Konta, do których użytkownik ma dostęp (etap 4.2).
     *
     * JEDYNE miejsce z odpowiedzią na pytanie „co widzi ten użytkownik" — pytają stąd i lista
     * (`MailController`), i `MessageVoter`, i komponent `MailList` z 4.4. Reguła dostępu w dwóch
     * egzemplarzach rozjechałaby się przy pierwszej komplikacji (konto wyłączone, dostęp czasowy)
     * i lista pokazywałaby maile, których detal by nie wpuścił.
     *
     * Jawne zapytanie zamiast leniwej kolekcji `User::getMailAccounts()`, bo tylko tak da się
     * narzucić `ORDER BY label` — panel kont w 4.3 ma mieć stałą kolejność, a nie tę, którą
     * akurat zwróci baza.
     *
     * @param User $user Zalogowany użytkownik
     *
     * @return list<MailAccount> Konta posortowane po etykiecie, np. [MailAccount("Poczta firmowa")]
     */
    public function findForUser(User $user): array {
        return $this->accessibleQueryBuilder($user)
            ->select('a')
            ->getQuery()
            ->getResult();
    }

    /**
     * To samo co `findForUser()`, ale same identyfikatory — w takiej postaci przyjmuje konta
     * `MessageRepository::searchPage()`.
     *
     * Osobne zapytanie (`SELECT a.id`), a nie mapowanie po encjach: nie hydratujemy obiektów,
     * których nikt nie ogląda. Widok listy woła obie metody, więc płaci dwa lekkie zapytania —
     * świadomie, bo alternatywą jest przekazywanie encji tam, gdzie kontrakt mówi `list<int>`.
     *
     * @param User $user Zalogowany użytkownik
     *
     * @return list<int> ID kont, np. [67, 68]
     */
    public function findIdsForUser(User $user): array {
        $rows = $this->accessibleQueryBuilder($user)
            ->select('a.id')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Konto o podanym ID, ale TYLKO jeśli użytkownik ma do niego dostęp (etap 4.3a).
     *
     * Wybór konta na liście przychodzi query stringiem (`?account=67`), czyli jest inputem
     * użytkownika. Ta metoda jest jedynym miejscem, przez które taki input wolno przepuścić:
     * cudze albo nieistniejące ID daje null, więc warstwa wyżej nie ma jak podać obcego konta
     * do `searchPage()`. Zero (brak parametru w żądaniu) trafia tu naturalnie i też daje null.
     *
     * @param User $user      Zalogowany użytkownik
     * @param int  $accountId ID żądanego konta z query stringa, np. 67 (0 = brak parametru)
     *
     * @return MailAccount|null Konto albo null, gdy nie istnieje lub użytkownik go nie ma
     */
    public function findOneForUser(User $user, int $accountId): ?MailAccount {
        if ($accountId <= 0) {
            return null;
        }

        return $this->accessibleQueryBuilder($user)
            ->select('a')
            ->andWhere('a.id = :id')
            ->setParameter('id', $accountId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Wspólne WHERE obu metod: przypisanie M2M `User ↔ MailAccount` plus stała kolejność.
     *
     * Wydzielone, żeby „kto ma dostęp" było zapisane raz — gdyby warunek się rozszedł między
     * `findForUser()` a `findIdsForUser()`, lista wiadomości przestałaby zgadzać się z listą kont.
     *
     * @param User $user Zalogowany użytkownik
     *
     * @return QueryBuilder Builder bez SELECT-a (dokłada go wołający)
     */
    private function accessibleQueryBuilder(User $user): QueryBuilder {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.users', 'u')
            ->where('u = :user')
            ->setParameter('user', $user)
            ->orderBy('a.label', 'ASC');
    }
}
