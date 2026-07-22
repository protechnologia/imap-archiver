<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function existsForContent(int $accountId, string $sha256): bool
    {
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
    
}
