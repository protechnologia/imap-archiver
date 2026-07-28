<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Model\MessageListPage;
use App\Repository\MessageRepository;
use App\Tests\Support\Fixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `MessageRepository::searchPage()` na PRAWDZIWYM Postgresie (etap 4.1).
 *
 * Te testy MUSZĄ iść na Postgresie, nie na SQLite „dla szybkości": sprawdzają zachowania
 * konkretnego silnika — `NULLS FIRST` przy `ORDER BY … DESC`, rozróżnianie wielkości liter
 * w `LIKE`, działanie `ESCAPE`. Na innej bazie przeszłyby na zielono przy zepsutym kodzie.
 *
 * Każdy test jedzie w transakcji cofanej przez `dama/doctrine-test-bundle`, więc dane nie
 * przeciekają między przypadkami i nie trzeba sprzątać ręcznie.
 */
class MessageRepositoryTest extends KernelTestCase {
    private EntityManagerInterface $em;
    private MessageRepository $repository;

    protected function setUp(): void {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(MessageRepository::class);
    }

    /**
     * Brak kont = brak dostępu do czegokolwiek. Repozytorium ma wtedy NIE pytać bazy
     * (`IN ()` byłoby błędem SQL) i oddać pustą, poprawnie opisaną stronę.
     */
    public function testBrakKontDajePustaStrone(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Cokolwiek');

        $page = $this->repository->searchPage([], null, 1, 50);

        $this->assertSame([], $page->items);
        $this->assertSame(0, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(1, $page->pages);
    }

    /**
     * Zawężenie do kont jest jedyną granicą dostępu w tym zapytaniu — wiadomość z konta spoza
     * listy nie ma prawa się pojawić.
     */
    public function testWiadomosciSpozaPodanychKontNieWchodzaDoWyniku(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $this->givenMessage($moje, 'Moja wiadomość');
        $this->givenMessage($obce, 'Cudza wiadomość');

        $page = $this->repository->searchPage([(int) $moje->getId()], null, 1, 50);

        $this->assertSame(['Moja wiadomość'], $this->subjectsOf($page));
    }

    /**
     * Postgres przy `DESC` stawia NULL-e NA POCZĄTKU. `Message.sent_at` jest nullable, więc bez
     * sztucznej kolumny sortującej maile z nieparsowalnym `Date` przykryłyby najnowsze na szczycie.
     */
    public function testWiadomoscBezDatyLadujeNaKoncuListy(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Bez daty');
        $this->givenMessage($account, 'Starsza', new \DateTimeImmutable('2026-01-01 08:00'));
        $this->givenMessage($account, 'Nowsza', new \DateTimeImmutable('2026-06-01 08:00'));

        $page = $this->repository->searchPage([(int) $account->getId()], null, 1, 50);

        $this->assertSame(['Nowsza', 'Starsza', 'Bez daty'], $this->subjectsOf($page));
    }

    /**
     * Przy równych datach sortuje `id DESC`. Bez tego tie-breaka paginacja offsetowa potrafi
     * gubić i dublować rekordy między stronami, bo Postgres nie gwarantuje stałej kolejności
     * wierszy o identycznym kluczu sortowania.
     */
    public function testPaginacjaNieGubiINieDublujeRekordowORownychDatach(): void {
        $account = $this->givenAccount();
        $data    = new \DateTimeImmutable('2026-06-01 08:00');
        foreach (['Pierwsza', 'Druga', 'Trzecia'] as $subject) {
            $this->givenMessage($account, $subject, $data);
        }

        $zebrane = [];
        foreach ([1, 2, 3] as $numer) {
            $strona = $this->repository->searchPage([(int) $account->getId()], null, $numer, 1);
            $this->assertCount(1, $strona->items);
            $zebrane[] = $strona->items[0]->getId();
        }

        $this->assertCount(3, array_unique($zebrane), 'Każda strona ma oddać INNY rekord');
    }

    /**
     * DQL nie ma `ILIKE`, a postgresowy `LIKE` rozróżnia wielkość liter — filtr porównuje przez
     * `LOWER()` po obu stronach, a fraza idzie przez `mb_strtolower()`, żeby złapać polskie znaki.
     */
    public function testFiltrIgnorujeWielkoscLiterTakzeWPolskichZnakach(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Zażółć GĘŚLĄ jaźń');
        $this->givenMessage($account, 'Zupełnie co innego');

        $page = $this->repository->searchPage([(int) $account->getId()], 'gęślą', 1, 50);

        $this->assertSame(['Zażółć GĘŚLĄ jaźń'], $this->subjectsOf($page));
    }

    /**
     * `%` i `_` z frazy użytkownika są eskejpowane (`ESCAPE '!'`). Bez tego samo „%" wpisane
     * w wyszukiwarkę wybrałoby CAŁE archiwum, a „_" byłby dziką kartą na jeden znak.
     */
    public function testWieloznacznikiZFrazyNieDzialajaJakWildcardy(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Rabat 50% na wszystko');
        $this->givenMessage($account, 'Plik_raport.pdf');
        $this->givenMessage($account, 'Zwykły temat bez znaków specjalnych');

        $procent = $this->repository->searchPage([(int) $account->getId()], '%', 1, 50);
        $this->assertSame(['Rabat 50% na wszystko'], $this->subjectsOf($procent));

        $podkreslnik = $this->repository->searchPage([(int) $account->getId()], '_', 1, 50);
        $this->assertSame(['Plik_raport.pdf'], $this->subjectsOf($podkreslnik));
    }

    /**
     * Strona poza zakresem (np. filtr obciął wyniki pod stopami) ma oddać OSTATNIĄ stronę,
     * a nie pustkę — i powiedzieć w `page`, którą naprawdę zwróciła.
     */
    public function testNumerStronyPozaZakresemJestPrzycinany(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Jedyna');

        $page = $this->repository->searchPage([(int) $account->getId()], null, 99, 50);

        $this->assertSame(1, $page->page);
        $this->assertSame(1, $page->pages);
        $this->assertSame(['Jedyna'], $this->subjectsOf($page));
    }

    /**
     * Rozmiar strony pochodzi z żądania (w 4.4 z `LiveProp`), więc jest przycinany do 1..200 —
     * inaczej można by zamówić całe archiwum jednym parametrem.
     */
    public function testRozmiarStronyJestPrzycinanyDoZakresu(): void {
        $account = $this->givenAccount();
        $this->givenMessage($account, 'Jedyna');

        $this->assertSame(1, $this->repository->searchPage([(int) $account->getId()], null, 1, 0)->perPage);
        $this->assertSame(200, $this->repository->searchPage([(int) $account->getId()], null, 1, 10_000)->perPage);
    }

    /**
     * Zapisuje konto gotowe do użycia w teście.
     *
     * @param string $label Etykieta konta, np. "Moje konto"
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(string $label = 'Konto testowe'): MailAccount {
        $account = Fixtures::account($label);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Zapisuje wiadomość na koncie.
     *
     * @param MailAccount             $account Konto źródłowe
     * @param string                  $subject Temat, np. "Faktura VAT 12/2026"
     * @param \DateTimeImmutable|null $date    Nagłówek `Date`; null = wiadomość bez daty
     *
     * @return Message Wiadomość z nadanym ID
     */
    private function givenMessage(MailAccount $account, string $subject, ?\DateTimeImmutable $date = null): Message {
        $message = Fixtures::message($account, $subject, $date);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Tematy wiadomości ze strony wyniku — w kolejności, w jakiej zwróciło je repozytorium.
     *
     * @param MessageListPage $page Wynik `searchPage()`
     *
     * @return list<string> Tematy, np. ["Nowsza", "Starsza"]
     */
    private function subjectsOf(MessageListPage $page): array {
        return array_map(static fn (Message $message): string => (string) $message->getSubject(), $page->items);
    }
}
