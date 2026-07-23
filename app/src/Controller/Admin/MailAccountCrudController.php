<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MailAccount;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

class MailAccountCrudController extends AbstractCrudController {
    public static function getEntityFqcn(): string {
        return MailAccount::class;
    }

    public function configureCrud(Crud $crud): Crud {
        return $crud
            ->setEntityLabelInSingular('Konto IMAP')
            ->setEntityLabelInPlural('Konta IMAP')
            ->setPageTitle(Crud::PAGE_INDEX, 'Konta IMAP')
            ->setPageTitle(Crud::PAGE_NEW, 'Nowe konto IMAP')
            ->setPageTitle(Crud::PAGE_EDIT, 'Edycja konta IMAP')
            ->setDefaultSort(['label' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->setLabel('Dodaj konto'))
            ->reorder(Crud::PAGE_INDEX, [Action::EDIT, Action::DELETE]);
    }

    public function configureFields(string $pageName): iterable {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('label', 'Nazwa');
        yield TextField::new('host', 'Host');
        yield IntegerField::new('port', 'Port');
        yield TextField::new('imapLogin', 'Login IMAP');
        yield TextField::new('folder', 'Folder')
            ->setHelp('Folder źródłowy importu (domyślnie INBOX).');

        // Na razie obsługujemy wyłącznie AuthType::Password (default encji), więc nie ma
        // pola wyboru typu — poświadczenie to po prostu hasło do skrzynki. Wybór XOAUTH2
        // dojdzie, gdy zapadnie decyzja o dostawcy poczty (etap 2).

        // Hasło do skrzynki. Pole niezwiązane z encją (mapped=false) — wartość przepisujemy
        // na encję w zdarzeniu formularza, a typ Doctrine `encrypted_string` zaszyfruje ją
        // przy zapisie. NIGDY na liście/podglądzie.
        $secretHelp = Crud::PAGE_EDIT === $pageName
            ? 'Zostaw puste, aby nie zmieniać hasła.'
            : 'Hasło do skrzynki IMAP.';

        yield TextField::new('secret', 'Hasło')
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => Crud::PAGE_NEW === $pageName,
            ])
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp($secretHelp)
            ->onlyOnForms();

        yield AssociationField::new('users', 'Dostęp (użytkownicy)')
            ->autocomplete()
            ->setHelp('Użytkownicy z dostępem do podglądu tego konta.');
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface {
        return $this->addSecretListener(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface {
        return $this->addSecretListener(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * Po zatwierdzeniu formularza bierze plaintext z pola `secret` (mapped=false) i
     * ustawia na encji (typ `encrypted_string` zaszyfruje przy zapisie). Puste pole =
     * hasło bez zmian — nie nadpisujemy istniejącego szyfrogramu.
     */
    private function addSecretListener(FormBuilderInterface $formBuilder): FormBuilderInterface {
        $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->isValid()) {
                return;
            }

            $plainSecret = $form->get('secret')->getData();
            if (null === $plainSecret || '' === $plainSecret) {
                return;
            }

            /** @var MailAccount $account */
            $account = $form->getData();
            $account->setSecret($plainSecret);
        });

        return $formBuilder;
    }
}
