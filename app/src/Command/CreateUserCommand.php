<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Tworzy użytkownika (domyślnie z rolą ROLE_USER; --admin nadaje ROLE_ADMIN).',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adres e-mail (login)')
            ->addArgument('password', InputArgument::OPTIONAL, 'Hasło (jeśli pominięte — zapyta interaktywnie)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Nadaj rolę ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $password = $input->getArgument('password');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('"%s" nie jest poprawnym adresem e-mail.', $email));

            return Command::FAILURE;
        }

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            $io->error(sprintf('Użytkownik "%s" już istnieje.', $email));

            return Command::FAILURE;
        }

        if ($password === null || $password === '') {
            $password = $io->askHidden('Podaj hasło');
        }

        if ($password === null || $password === '') {
            $io->error('Hasło nie może być puste.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($input->getOption('admin') ? ['ROLE_ADMIN'] : []);
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Utworzono użytkownika "%s" z rolami: %s.',
            $email,
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
