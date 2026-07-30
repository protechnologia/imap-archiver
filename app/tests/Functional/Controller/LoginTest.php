<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Logowanie formularzem (etap 1.2) i to, gdzie ląduje się po nim (etap 4.3a).
 *
 * Pozostałe testy logują przez `loginUser()`, czyli z pominięciem formularza — to jedyne miejsce,
 * które przechodzi PRAWDZIWĄ ścieżkę uwierzytelnienia: POST na `check_path`, weryfikację hasła
 * hasherem i stateless CSRF (token renderuje się jako literał `csrf-token`, patrz CLAUDE.md).
 *
 * `testPoZalogowaniuLadujemyWPoczcie()` pilnuje `default_target_path` z `security.yaml`. To jedna
 * linijka konfiguracji, ale literówka w nazwie trasy wyrzuca użytkownika po zalogowaniu na 404 —
 * i żaden inny test tego nie dotyka, bo wszystkie zaczynają już od zalogowanej sesji.
 */
class LoginTest extends WebTestCase {
    
    private const PASSWORD = 'Tajne123!';

    private KernelBrowser $client;

    protected function setUp(): void {
        $this->client = self::createClient();
    }

    public function testPoZalogowaniuLadujemyWPoczcie(): void {
        $this->givenUserWithPassword('user@example.com');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Zaloguj się', [
            '_username' => 'user@example.com',
            '_password' => self::PASSWORD,
        ]);

        $this->assertResponseRedirects('/mail');
    }

    public function testZleHasloWracaNaFormularz(): void {
        $this->givenUserWithPassword('user@example.com');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Zaloguj się', [
            '_username' => 'user@example.com',
            '_password' => 'nie-to-haslo',
        ]);

        $this->assertResponseRedirects('/login');
    }

    /**
     * Zapisuje użytkownika z PRAWDZIWYM hashem hasła — `EntityFactory` wstawia atrapę, której
     * hasher nie zaakceptuje, a tu przechodzimy przez pełne uwierzytelnienie.
     *
     * @param string $email Adres e-mail, np. "user@example.com"
     *
     * @return User Zapisany użytkownik
     */
    private function givenUserWithPassword(string $email): User {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = EntityFactory::user($email);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
