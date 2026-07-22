<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Message;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Diagnostyczny podgląd zaimportowanych wiadomości w EasyAdmin (etap 3.4). READ-ONLY.
 *
 * Tani wgląd admina w efekt importu (3.3): lista + detal, BEZ `new/edit/delete`. `Message` to indeks
 * (edycja bez sensu), a kasowanie zarchiwizowanej poczty to etap 6 z audytem — tu żadnej mutacji.
 * To NIE jest podgląd dla użytkowników (trójpanelowy Twig/UX + Voter + sandbox iframe) — ten powstaje
 * w etapie 5; treści maila (`body`) tu świadomie nie renderujemy, pokazujemy tylko metadane indeksu.
 */
class MessageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Message::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Wiadomość')
            ->setEntityLabelInPlural('Wiadomości')
            ->setPageTitle(Crud::PAGE_INDEX, 'Zaimportowane wiadomości')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (Message $m): string => (string) $m->getSubject() ?: 'Wiadomość')
            ->setDefaultSort(['date' => 'DESC'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Indeks = tylko wgląd: żadnego zakładania/edycji/kasowania, jedynie przejście do detalu.
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('subject', 'Temat');
        yield TextField::new('fromName', 'Nadawca');
        yield TextField::new('fromEmail', 'E-mail nadawcy');
        yield DateTimeField::new('date', 'Data')->setFormat('yyyy-MM-dd HH:mm');
        yield Field::new('size', 'Rozmiar')
            ->formatValue(fn (?int $bytes): string => $this->formatBytes((int) $bytes))
            ->setTextAlign('right');
        yield BooleanField::new('verified', 'Zweryfikowana')->renderAsSwitch(false);
        yield BooleanField::new('hasAttachments', 'Załączniki')->renderAsSwitch(false)->onlyOnIndex();
        yield TextField::new('folder', 'Folder')->onlyOnDetail();
        yield AssociationField::new('account', 'Konto');

        // Detal — pełne metadane indeksu + tożsamość/adres pliku w archiwum.
        yield TextField::new('messageId', 'Message-ID')->onlyOnDetail();
        yield TextField::new('sha256', 'SHA-256')->onlyOnDetail();
        yield IntegerField::new('imapUid', 'UID IMAP')->onlyOnDetail();
        yield TextField::new('archivePath', 'Ścieżka w archiwum')->onlyOnDetail();

        // Lista załączników (metadane: nazwa, MIME, rozmiar) — własny szablon, bez osobnego CRUD-a.
        yield Field::new('attachments', 'Załączniki')
            ->onlyOnDetail()
            ->setTemplatePath('admin/message_attachments.html.twig');
    }

    /**
     * Formatuje rozmiar w bajtach na czytelną jednostkę (B/KB/MB) do wyświetlenia.
     *
     * @param int $bytes Rozmiar w bajtach, np. 49189
     *
     * @return string Sformatowany rozmiar, np. "48,0 KB"
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
