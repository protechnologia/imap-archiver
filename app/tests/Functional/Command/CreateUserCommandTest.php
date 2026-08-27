<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `app:user:create` — jedyna droga zakładania kont do logowania (etap 1.2).
 *
 * Najważniejszy przypadek to `testHasloJestZahaszowaneNigdyPlaintext()`. Komenda przyjmuje hasło
 * argumentem wiersza poleceń, więc pomyłka akurat w tym miejscu (`setPassword($password)` zamiast
 * `hashPassword()`) zapisałaby jawne hasła użytkowników — objawem byłoby wyłącznie „nie mogę się
 * zalogować", co niekoniecznie skojarzy się z przyczyną.
 */
class CreateUserCommandTest extends KernelTestCase {
    private const PASSWORD = 'Tajne123!';

    private CommandTester $command;
    private EntityManagerInterface $em;
    private UserRepository $users;

    protected function setUp(): void {
        self::bootKernel();

        $this->em    = self::getContainer()->get(EntityManagerInterface::class);
        $this->users = self::getContainer()->get(UserRepository::class);

        $this->command = new CommandTester((new Application(self::$kernel))->find('app:user:create'));
    }

    public function testTworzyUzytkownikaZRolaUser(): void {
        $status = $this->command->execute(['email' => 'nowy@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(['ROLE_USER'], $this->userByEmail('nowy@example.com')->getRoles());
    }

    /**
     * Sprawdzamy oba kierunki: że w kolumnie nie ma jawnego hasła ORAZ że hash jest prawdziwy
     * (hasher go akceptuje) — sam „inny string niż hasło" spełniałby pierwszy warunek.
     */
    public function testHasloJestZahaszowaneNigdyPlaintext(): void {
        $this->command->execute(['email' => 'nowy@example.com', 'password' => self::PASSWORD]);

        $user = $this->userByEmail('nowy@example.com');

        $this->assertNotSame(self::PASSWORD, $user->getPassword());
        $this->assertStringNotContainsString(self::PASSWORD, $user->getPassword());
        $this->assertTrue($this->hasher()->isPasswordValid($user, self::PASSWORD));
    }

    public function testFlagaAdminNadajeRoleAdmin(): void {
        $this->command->execute(['email' => 'szef@example.com', 'password' => self::PASSWORD, '--admin' => true]);

        $this->assertContains('ROLE_ADMIN', $this->userByEmail('szef@example.com')->getRoles());
    }

    /**
     * `email` jest unikatem w bazie — komenda ma to wyłapać sama i oddać czytelny komunikat,
     * zamiast pozwolić Doctrine'owi wywalić się wyjątkiem o naruszeniu klucza.
     */
    public function testDuplikatEmailaNieTworzyDrugiegoUzytkownika(): void {
        $this->em->persist(EntityFactory::user('zajety@example.com'));
        $this->em->flush();

        $status = $this->command->execute(['email' => 'zajety@example.com', 'password' => self::PASSWORD]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('już istnieje', $this->command->getDisplay());
        $this->assertCount(1, $this->users->findBy(['email' => 'zajety@example.com']));
    }

    public function testNiepoprawnyEmailJestOdrzucany(): void {
        $status = $this->command->execute(['email' => 'to-nie-jest-email', 'password' => self::PASSWORD]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertNull($this->users->findOneBy(['email' => 'to-nie-jest-email']));
    }

    /**
     * Hasło pominięte w argumentach jest pytane interaktywnie (ukrytym promptem) — po to, żeby
     * nie musiało zostawać w historii powłoki.
     */
    public function testPominieteHasloJestPytaneInteraktywnie(): void {
        $this->command->setInputs([self::PASSWORD]);

        $status = $this->command->execute(['email' => 'pytany@example.com']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertTrue($this->hasher()->isPasswordValid($this->userByEmail('pytany@example.com'), self::PASSWORD));
    }

    public function testPusteHasloJestOdrzucane(): void {
        $this->command->setInputs(['']);

        $status = $this->command->execute(['email' => 'bezhasla@example.com']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertNull($this->users->findOneBy(['email' => 'bezhasla@example.com']));
    }

    /**
     * Użytkownik z bazy (po `clear()`, żeby wartości przeszły przez hydratację), z asercją,
     * że w ogóle powstał.
     *
     * @param string $email Adres e-mail, np. "nowy@example.com"
     *
     * @return User Zapisany użytkownik
     */
    private function userByEmail(string $email): User {
        $this->em->clear();
        $user = $this->users->findOneBy(['email' => $email]);

        $this->assertInstanceOf(User::class, $user, sprintf('Nie znaleziono użytkownika "%s".', $email));

        return $user;
    }

    /**
     * @return UserPasswordHasherInterface Hasher z kontenera (w `test` z obniżonym kosztem)
     */
    private function hasher(): UserPasswordHasherInterface {
        return self::getContainer()->get(UserPasswordHasherInterface::class);
    }
}
