<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MailAccount;
use App\Entity\User;
use App\Repository\MailAccountRepository;
use App\Tests\Support\Fixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `MailAccountRepository` — jedyne źródło odpowiedzi „co widzi ten użytkownik" (etap 4.2).
 *
 * Testujemy je osobno, bo korzystają z niego trzy niezależne miejsca: lista wiadomości,
 * `MessageVoter` i (od 4.4) komponent `MailList`. Gdyby zawężenie po M2M tu puściło, wyciek
 * byłby natychmiast we wszystkich trzech naraz.
 */
class MailAccountRepositoryTest extends KernelTestCase {
    private EntityManagerInterface $em;
    private MailAccountRepository $repository;

    protected function setUp(): void {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(MailAccountRepository::class);
    }

    /**
     * Konta nieprzypisane nie mogą się pojawić, a przypisane mają wyjść posortowane po etykiecie
     * — panel kont w 4.3 potrzebuje kolejności deterministycznej, nie „takiej, jaką zwróci baza".
     */
    public function testZwracaTylkoPrzypisaneKontaPosortowanePoEtykiecie(): void {
        $user = Fixtures::user('user@example.com');
        $this->em->persist($user);

        $zetka = $this->givenAccount('Zetka', $user);
        $alfa  = $this->givenAccount('Alfa', $user);
        $obce  = $this->givenAccount('Cudze konto');
        $this->em->flush();

        $konta = $this->repository->findForUser($user);

        $this->assertSame(['Alfa', 'Zetka'], array_map(static fn (MailAccount $a): string => $a->getLabel(), $konta));
        $this->assertNotContains($obce, $konta);
        $this->assertSame([(int) $alfa->getId(), (int) $zetka->getId()], $this->repository->findIdsForUser($user));
    }

    /**
     * Użytkownik bez przypisań to POPRAWNY stan (świeże konto, admin, który nie dopisał się do
     * skrzynki) — ma dostać pustkę, a nie wyjątek ani wszystko.
     */
    public function testUzytkownikBezPrzypisanDostajePustaListe(): void {
        $user = Fixtures::user('outsider@example.com');
        $this->em->persist($user);
        $this->givenAccount('Cudze konto');
        $this->em->flush();

        $this->assertSame([], $this->repository->findForUser($user));
        $this->assertSame([], $this->repository->findIdsForUser($user));
    }

    /**
     * Zapisuje konto, opcjonalnie przypisując do niego użytkownika.
     *
     * @param string    $label Etykieta konta, np. "Alfa"
     * @param User|null $user  Użytkownik do przypisania albo null (konto niczyje)
     *
     * @return MailAccount Konto (bez `flush()` — robi go test)
     */
    private function givenAccount(string $label, ?User $user = null): MailAccount {
        $account = Fixtures::account($label);
        if ($user !== null) {
            $account->addUser($user);
        }
        $this->em->persist($account);

        return $account;
    }
}
