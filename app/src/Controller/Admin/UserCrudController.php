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

/**
 * Panel EasyAdmin dla encji User — zakładanie kont, edycja ról i reset hasła.
 * Dostępny tylko dla ROLE_ADMIN (ograniczenie na poziomie dashboardu/security).
 *
 * Hasło nie jest polem encji w formularzu: zbieramy plaintext przez pole
 * `password` (mapped=false) i hashujemy w listenerze POST_SUBMIT, więc do encji
 * trafia już hash. Patrz addPasswordHashListener().
 */
class UserCrudController extends AbstractCrudController {
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher) {
    }

    /** Encja obsługiwana przez ten CRUD (wymagane przez EasyAdmin). */
    public static function getEntityFqcn(): string {
        return User::class;
    }

    /** Etykiety encji, tytuły stron i domyślne sortowanie listy (po e-mailu rosnąco). */
    public function configureCrud(Crud $crud): Crud {
        return $crud
            ->setEntityLabelInSingular('Użytkownik')
            ->setEntityLabelInPlural('Użytkownicy')
            ->setPageTitle(Crud::PAGE_INDEX, 'Użytkownicy')
            ->setPageTitle(Crud::PAGE_NEW, 'Nowy użytkownik')
            ->setPageTitle(Crud::PAGE_EDIT, 'Edycja użytkownika')
            ->setDefaultSort(['email' => 'ASC']);
    }

    /** Zmienia etykietę przycisku „Dodaj" i ustala kolejność akcji w wierszu listy. */
    public function configureActions(Actions $actions): Actions {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->setLabel('Dodaj użytkownika'))
            ->reorder(Crud::PAGE_INDEX, [Action::EDIT, Action::DELETE]);
    }

    /**
     * Pola formularzy i list. Zależnie od strony ($pageName) renderujemy je inaczej:
     * e-mail jako tekst vs EmailField, hasło tylko na formularzach itd.
     */
    public function configureFields(string $pageName): iterable {
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

    /** Formularz dodawania — podpina listener hashujący hasło (patrz addPasswordHashListener()). */
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface {
        return $this->addPasswordHashListener(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    /** Formularz edycji — ten sam listener; puste pole hasła = bez zmiany. */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface {
        return $this->addPasswordHashListener(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * Po zatwierdzeniu formularza bierze plaintext z pola `password` (mapped=false),
     * hashuje i ustawia na encji. Puste pole = hasło bez zmian.
     */
    private function addPasswordHashListener(FormBuilderInterface $formBuilder): FormBuilderInterface {
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
