<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\User;
use App\Service\ArchiveStorage;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render treści wiadomości: przełącznik tekst/HTML i trasa `/mail/{id}/body` (etap 4.5).
 *
 * Osobny plik od `MailControllerTest`, bo przedmiot jest inny: tamten pilnuje DOSTĘPU do skrzynki
 * i kontraktu z Turbo, ten — tego, co widać w podglądzie i z jakimi nagłówkami wychodzi treść.
 *
 * Testy idą przez PRAWDZIWE pliki `.eml` w archiwum, a nie przez atrapę czytnika. Powód jest ten
 * sam, dla którego `MailBodyReader` w ogóle powstał: cała ścieżka ma sens tylko wtedy, gdy warianty
 * treści biorą się z bajtów MIME. Atrapa sprawdzałaby nasze wyobrażenie o tym, co jest w mailu.
 * Archiwum testowe to `var/test-archive` (przykrycie `ArchiveStorage` w `when@test` — patrz
 * `services.yaml`); `dama` cofa transakcję bazy, ale PLIKÓW nie, więc sprzątamy je sami.
 *
 * Najważniejsze przypadki:
 *  - `testNieprzypisanyUzytkownikNieDostanieTresciPrzezTraseBody()` — `/body` jest OSOBNYM punktem
 *    wejścia i pyta Votera samo. Gdyby zapomniało, cudza korespondencja byłaby do wzięcia adresem
 *    z palca, przy zielonych testach podglądu.
 *  - `testTrescHtmlWychodziZKompletemNaglowkowBezpieczenstwa()` — nagłówki są jedyną warstwą,
 *    której nie widać w interfejsie, więc tylko test odróżni działający CSP od skasowanej linijki.
 */
class MailPreviewTest extends WebTestCase {

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ArchiveStorage $archive;
    private Filesystem $filesystem;
    private string $archiveDir;

    protected function setUp(): void {
        $this->client     = self::createClient();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->archive    = self::getContainer()->get(ArchiveStorage::class);
        $this->filesystem = new Filesystem();
        $this->archiveDir = self::getContainer()->getParameter('kernel.project_dir') . '/var/test-archive';
    }

    protected function tearDown(): void {
        parent::tearDown();
        // Rollback `dama` obejmuje bazę, nie dysk — bez tego pliki zostają między przebiegami.
        $this->filesystem->remove($this->archiveDir);
    }

    /**
     * Mail z obiema wersjami startuje na TEKŚCIE: bezpieczniejszy (nie uruchamia sanityzacji ani
     * drugiego żądania) i wystarczający do przejrzenia archiwum. HTML jest świadomym wyborem.
     */
    public function testMailZObiemaWersjamiPokazujeDomyslnieTekst(): void {
        [$user, $message] = $this->givenMessageWithEml('multipart-alternative.eml');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('pre', 'To jest wersja tekstowa.');
        $this->assertSelectorNotExists('iframe', 'Wariant tekstowy renderuje się wprost w kolumnie, bez iframe’a');
    }

    /**
     * Przy obu wariantach ŻADNA pozycja nie jest zablokowana — użytkownik ma realny wybór.
     */
    public function testPrzelacznikMaObiePozycjeCzynneGdySaObaWarianty(): void {
        [$user, $message] = $this->givenMessageWithEml('multipart-alternative.eml');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[role="group"] [aria-current="true"]');
        $this->assertSelectorNotExists('[role="group"] [aria-disabled="true"]');
        $this->assertSame(1, $crawler->filter('[role="group"] a')->count(), 'Druga pozycja ma być linkiem, nie martwym napisem');
    }

    public function testZadanieWersjiHtmlPokazujeSandboksowanyIframe(): void {
        [$user, $message] = $this->givenMessageWithEml('multipart-alternative.eml');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail/' . $message->getId() . '?view=html');

        $this->assertResponseIsSuccessful();

        $iframe = $crawler->filter('iframe');
        $this->assertSame(1, $iframe->count());
        $this->assertSame('/mail/' . $message->getId() . '/body', $iframe->attr('src'));

        // Bez `allow-scripts` i `allow-same-origin` — treść nie wykona kodu i nie sięgnie do sesji.
        $sandbox = (string) $iframe->attr('sandbox');
        $this->assertStringNotContainsString('allow-scripts', $sandbox);
        $this->assertStringNotContainsString('allow-same-origin', $sandbox);
    }

    /**
     * Mail HTML-only: pozycja „Tekst" ZABLOKOWANA, nie ukryta — „ta wiadomość nie ma wersji
     * tekstowej" jest informacją o wiadomości, a nie brakiem funkcji aplikacji.
     */
    public function testMailHtmlOnlyBlokujePozycjeTekstowa(): void {
        [$user, $message] = $this->givenMessageWithEml('html-only.eml');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('iframe', 'Przy braku tekstu podgląd ma zejść na HTML, a nie pokazać pustki');

        $zablokowana = $crawler->filter('[role="group"] [aria-disabled="true"]');
        $this->assertSame(1, $zablokowana->count());
        $this->assertSame('Tekst', trim($zablokowana->text()));
        $this->assertSame(0, $crawler->filter('[role="group"] a')->count(), 'Nie ma czego wybierać, więc nie ma linków');
    }

    public function testMailTekstowyBlokujePozycjeHtml(): void {
        [$user, $message] = $this->givenMessageWithEml('simple.eml');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('iframe');

        $zablokowana = $crawler->filter('[role="group"] [aria-disabled="true"]');
        $this->assertSame(1, $zablokowana->count());
        $this->assertSame('HTML', trim($zablokowana->text()));
    }

    /**
     * Rekord bez pliku w archiwum (nieudany bind mount, zaślepki sprzed 4.5). Podgląd mówi wprost,
     * że treści nie ma — bez podstawiania czegokolwiek z indeksu i bez pięćsetki.
     */
    public function testWiadomoscBezPlikuMowiZeTresciNieMaWArchiwum(): void {
        $account = $this->givenAccount();
        $user    = $this->givenUser('user@example.com', $account);
        $message = EntityFactory::message($account, 'Bez pliku', new \DateTimeImmutable('2026-06-01 08:00'));
        $this->em->persist($message);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Nie ma treści tej wiadomości w archiwum');
        $this->assertSelectorNotExists('[role="group"]', 'Nie ma wariantów, więc nie ma czym przełączać');
    }

    public function testTrescHtmlWychodziZKompletemNaglowkowBezpieczenstwa(): void {
        [$user, $message] = $this->givenMessageWithEml('html-only.eml');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId() . '/body');

        $this->assertResponseIsSuccessful();

        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp, 'Bez tego treść maila może żądać zdalnych zasobów — piksele śledzące');
        $this->assertStringContainsString("base-uri 'none'", $csp, '`base-uri` NIE dziedziczy z `default-src` — musi być wypisane jawnie');
        $this->assertStringContainsString("form-action 'none'", $csp, '`form-action` NIE dziedziczy z `default-src`');
        $this->assertMatchesRegularExpression("/style-src [^;]*'nonce-[0-9a-f]{32}'/", $csp, 'Własny arkusz puszczamy nonce’em, nie `unsafe-inline`');

        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $this->assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    /**
     * `/body` jest OSOBNYM punktem wejścia i pyta Votera SAM. Zabezpieczenie podglądu nie
     * rozciąga się na niego w żaden sposób — gdyby o tym zapomnieć, cudzą korespondencję
     * dałoby się wziąć adresem z palca, przy zielonych testach `MailControllerTest`.
     */
    public function testNieprzypisanyUzytkownikNieDostanieTresciPrzezTraseBody(): void {
        [, $message] = $this->givenMessageWithEml('html-only.eml');
        $obcy        = $this->givenUser('outsider@example.com');

        $this->client->loginUser($obcy);
        $this->client->request('GET', '/mail/' . $message->getId() . '/body');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Pytanie o wariant HTML wiadomości, która go nie ma, to pytanie o nieistniejący zasób.
     * Trasa świadomie NIE podaje wtedy wersji tekstowej — ta renderuje się w kolumnie podglądu.
     */
    public function testTrasaBodyDaje404DlaWiadomosciBezHtmla(): void {
        [$user, $message] = $this->givenMessageWithEml('simple.eml');

        $this->client->loginUser($user);
        $this->client->request('GET', '/mail/' . $message->getId() . '/body');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Zapisuje fixturę `.eml` w archiwum testowym i tworzy wskazującą na nią wiadomość
     * razem z użytkownikiem, który ma do niej dostęp.
     *
     * @param string $fixture Nazwa pliku z `tests/Fixtures/eml/`, np. "html-only.eml"
     *
     * @return array{0: User,1: Message} [użytkownik z dostępem, wiadomość]
     */
    private function givenMessageWithEml(string $fixture): array {
        $account = $this->givenAccount();
        $user    = $this->givenUser('user@example.com', $account);

        $raw      = (string) file_get_contents(__DIR__ . '/../../Fixtures/eml/' . $fixture);
        $archived = $this->archive->store((int) $account->getId(), new \DateTimeImmutable('2026-06-16 10:00'), $raw);

        $message = EntityFactory::message($account, 'Wiadomość z treścią', new \DateTimeImmutable('2026-06-01 08:00'));
        $message->setArchivePath($archived->relativePath);
        $message->setSha256($archived->sha256);
        $message->setSize($archived->size);
        $this->em->persist($message);
        $this->em->flush();

        return [$user, $message];
    }

    /**
     * Zapisuje konto IMAP.
     *
     * @param string $label Etykieta konta, np. "Konto testowe"
     *
     * @return MailAccount Konto z nadanym ID
     */
    private function givenAccount(string $label = 'Konto testowe'): MailAccount {
        $account = EntityFactory::account($label);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Zapisuje użytkownika, opcjonalnie z dostępem do konta.
     *
     * @param string           $email   Adres e-mail, np. "user@example.com"
     * @param MailAccount|null $account Konto do przypisania albo null (użytkownik bez dostępu)
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
}
