<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Użytkownik')
            ->setEntityLabelInPlural('Użytkownicy')
            ->setPageTitle(Crud::PAGE_INDEX, 'Użytkownicy')
            ->setPageTitle(Crud::PAGE_NEW, 'Nowy użytkownik')
            ->setPageTitle(Crud::PAGE_EDIT, 'Edycja użytkownika')
            ->setDefaultSort(['email' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->setLabel('Dodaj użytkownika'))
            ->reorder(Crud::PAGE_INDEX, [Action::EDIT, Action::DELETE]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        // Na liście/podglądzie zwykły tekst (bez linku mailto:), na formularzach
        // EmailField — zachowuje input type="email" i walidację adresu.
        yield in_array($pageName, [Crud::PAGE_INDEX, Crud::PAGE_DETAIL], true)
            ? TextField::new('email', 'Adres e-mail')
            : EmailField::new('email', 'Adres e-mail');

        yield ChoiceField::new('roles', 'Role')
            ->allowMultipleChoices()
            ->setChoices([
                'Administrator' => 'ROLE_ADMIN',
                'Użytkownik' => 'ROLE_USER',
            ])
            ->renderExpanded()
            ->renderAsBadges([
                'ROLE_ADMIN' => 'danger',
                'ROLE_USER' => 'secondary',
            ])
            // ROLE_USER i tak dokleja getRoles(); pokazujemy tylko realnie nadane role.
            ->setHelp('ROLE_USER jest przypisywana automatycznie każdemu kontu.');

        // Pole niezwiązane z encją (mapped=false) — hashujemy je w zdarzeniu formularza.
        // Na liście/podglądzie nieobecne; przy edycji puste = bez zmiany hasła.
        $passwordHelp = Crud::PAGE_EDIT === $pageName
            ? 'Zostaw puste, aby nie zmieniać hasła.'
            : 'Minimum 8 znaków.';

        yield TextField::new('password', 'Hasło')
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => Crud::PAGE_NEW === $pageName,
                'first_options' => ['label' => 'Hasło'],
                'second_options' => ['label' => 'Powtórz hasło'],
                'invalid_message' => 'Hasła nie są identyczne.',
            ])
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp($passwordHelp)
            ->onlyOnForms();
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addPasswordHashListener(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addPasswordHashListener(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * Po zatwierdzeniu formularza bierze plaintext z pola `password` (mapped=false),
     * hashuje i ustawia na encji. Puste pole = hasło bez zmian.
     */
    private function addPasswordHashListener(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->isValid()) {
                return;
            }

            $plainPassword = $form->get('password')->getData();
            if (null === $plainPassword || '' === $plainPassword) {
                return;
            }

            /** @var User $user */
            $user = $form->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        });

        return $formBuilder;
    }
}
