<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController {
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator) {
    }

    public function index(): Response {
        // Pulpit nie ma własnej treści — od razu kierujemy na listę użytkowników.
        return $this->redirect(
            $this->adminUrlGenerator->setController(UserCrudController::class)->generateUrl()
        );
    }

    public function configureAssets(): Assets {
        // Arkusz z nadpisaniami stylów EA (m.in. odwrócony układ pól boolean na detalu).
        // Prawdziwy <link>, nie wstrzykiwany inline <style> — ten okazał się zawodny.
        return Assets::new()->addCssFile('css/easyadmin-overrides.css');
    }

    public function configureDashboard(): Dashboard {
        return Dashboard::new()
            ->setTitle('imap-archiver — administracja')
            ->setFaviconPath('favicon.ico');
    }

    public function configureMenuItems(): iterable {
        yield MenuItem::linkToUrl('Powrót do aplikacji', 'fa fa-arrow-left', $this->generateUrl('app_home'));
        yield MenuItem::section('Zarządzanie');
        yield MenuItem::linkTo(UserCrudController::class, 'Użytkownicy', 'fa fa-users');
        yield MenuItem::linkTo(MailAccountCrudController::class, 'Konta IMAP', 'fa fa-envelope');
        yield MenuItem::section('Archiwum');
        yield MenuItem::linkTo(MessageCrudController::class, 'Wiadomości', 'fa fa-inbox');
    }
}
