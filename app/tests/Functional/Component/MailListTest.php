<?php

declare(strict_types=1);

namespace App\Tests\Functional\Component;

use App\Entity\MailAccount;
use App\Entity\User;
use App\Tests\Fixtures\EntityFactory;
use App\Twig\Components\MailList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * ZAWĘŻENIE DOSTĘPU w komponencie listy (etap 4.4).
 *
 * Dlaczego to osobny test, skoro `MailControllerTest` sprawdza już `?account=`: komponent ma
 * WŁASNĄ ścieżkę HTTP. Żądania live idą na `/_components/MailList`, więc kontroler i jego
 * zawężenie w ogóle się nie wykonują — gdyby `MailList` ufał `$accountId` na słowo, ta trasa
 * byłaby wejściem do cudzej poczty, a wszystkie testy kontrolera dalej świeciłyby na zielono.
 *
 * `$accountId` jest podpisany checksumem z `APP_SECRET`, więc użytkownik nie podmieni go
 * w przeglądarce. To jednak zabezpiecza tylko przed FAŁSZOWANIEM wartości, a nie odpowiada na
 * pytanie, czy dany użytkownik ma do tego konta prawo — dlatego komponent i tak pyta
 * `MailAccountRepository`, a te testy tego pilnują.
 *
 * Najważniejszy przypadek: `testWidokWszystkichKontNiePokazujeCudzejPoczty()` — przy `accountId`
 * równym null nie ma czego podpisywać, więc „wszystkie" MUSI znaczyć „wszystkie moje".
 */
class MailListTest extends WebTestCase {
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void {
        $this->client = self::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Widok „wszystkie konta" pokazuje pocztę użytkownika i NIE pokazuje cudzej.
     *
     * To gałąź `accountId === null`, czyli ta bez podpisu do sprawdzenia. Jedyną obroną jest tu
     * `findIdsForUser()` — bez niego komponent pobrałby wszystkie wiadomości z bazy.
     */
    public function testWidokWszystkichKontNiePokazujeCudzejPoczty(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $user = $this->givenUser('user@example.com', $moje);
        $this->givenMessage($moje, 'Moja wiadomość');
        $this->givenMessage($obce, 'Cudza wiadomość');

        $this->client->loginUser($user);
        $rendered = $this->createLiveComponent(MailList::class, client: $this->client)->render()->toString();

        $this->assertStringContainsString('Moja wiadomość', $rendered);
        $this->assertStringNotContainsString('Cudza wiadomość', $rendered);
    }

    /**
     * Wskazanie CUDZEGO konta nie daje cudzej poczty — widok wraca do „wszystkie moje".
     *
     * Podpis `LiveProp` przepuściłby tę wartość, bo w teście montujemy komponent po stronie
     * serwera; obroną jest wyłącznie `findOneForUser()`, które na cudzym ID zwraca null.
     */
    public function testObceKontoNieDajeCudzejPoczty(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $user = $this->givenUser('user@example.com', $moje);
        $this->givenMessage($moje, 'Moja wiadomość');
        $this->givenMessage($obce, 'Cudza wiadomość');

        $this->client->loginUser($user);
        $rendered = $this->createLiveComponent(MailList::class, ['accountId' => $obce->getId()], $this->client)
            ->render()
            ->toString();

        $this->assertStringNotContainsString('Cudza wiadomość', $rendered);
        $this->assertStringContainsString(
            'Moja wiadomość',
            $rendered,
            'Obce konto ma być zignorowane, a nie wyczyścić listę',
        );
    }

    /**
     * Wskazanie WŁASNEGO konta zawęża listę do niego.
     *
     * Kontrapunkt dla dwóch testów wyżej: gdyby komponent po prostu ignorował `accountId`, one
     * przechodziłyby tak samo, a panel kont przestałby cokolwiek robić.
     */
    public function testWlasneKontoZawezaListe(): void {
        $pierwsze = $this->givenAccount('Konto A');
        $drugie   = $this->givenAccount('Konto B');
        $user     = $this->givenUser('user@example.com', $pierwsze);
        $drugie->addUser($user);
        $this->em->flush();
        $this->givenMessage($pierwsze, 'Mail z konta A');
        $this->givenMessage($drugie, 'Mail z konta B');

        $this->client->loginUser($user);
        $rendered = $this->createLiveComponent(MailList::class, ['accountId' => $pierwsze->getId()], $this->client)
            ->render()
            ->toString();

        $this->assertStringContainsString('Mail z konta A', $rendered);
        $this->assertStringNotContainsString('Mail z konta B', $rendered);
    }

    /**
     * Użytkownik bez przypisanych kont widzi pustą listę, a nie cudzą pocztę.
     */
    public function testUzytkownikBezKontWidziPustaListe(): void {
        $obce = $this->givenAccount('Obce konto');
        $this->givenMessage($obce, 'Cudza wiadomość');
        $obcy = $this->givenUser('outsider@example.com');

        $this->client->loginUser($obcy);
        $rendered = $this->createLiveComponent(MailList::class, client: $this->client)->render()->toString();

        $this->assertStringNotContainsString('Cudza wiadomość', $rendered);
    }

    /**
     * Szukanie NIE omija zawężenia do kont użytkownika.
     *
     * Fraza jest writable, czyli inputem użytkownika — pilnujemy, żeby nie stała się drogą do
     * cudzej korespondencji, gdyby kiedyś ktoś dołożył szukanie „po wszystkim".
     */
    public function testSzukanieNieSiegaDoCudzychKont(): void {
        $moje = $this->givenAccount('Moje konto');
        $obce = $this->givenAccount('Obce konto');
        $user = $this->givenUser('user@example.com', $moje);
        $this->givenMessage($moje, 'Faktura moja');
        $this->givenMessage($obce, 'Faktura cudza');

        $this->client->loginUser($user);
        $rendered = $this->createLiveComponent(MailList::class, client: $this->client)
            ->set('query', 'Faktura')
            ->render()
            ->toString();

        $this->assertStringContainsString('Faktura moja', $rendered);
        $this->assertStringNotContainsString('Faktura cudza', $rendered);
    }

    /**
     * Zapisuje konto IMAP.
     *
     * @param string $label Etykieta konta, np. "Moje konto"
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(string $label): MailAccount {
        $account = EntityFactory::account($label);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Zapisuje użytkownika, opcjonalnie z dostępem do konta.
     *
     * @param string           $email   Adres e-mail, np. "user@example.com"
     * @param MailAccount|null $account Konto do przypisania albo null
     *
     * @return User Użytkownik z nadanym ID
     */
    private function givenUser(string $email, ?MailAccount $account = null): User {
        $user = EntityFactory::user($email);
        $account?->addUser($user);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Zapisuje wiadomość na koncie.
     *
     * @param MailAccount $account Konto źródłowe
     * @param string      $subject Temat, np. "Faktura VAT 12/2026"
     */
    private function givenMessage(MailAccount $account, string $subject): void {
        $this->em->persist(EntityFactory::message($account, $subject, new \DateTimeImmutable('2026-06-01 08:00')));
        $this->em->flush();
    }
}
