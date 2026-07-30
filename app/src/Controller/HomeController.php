<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Korzeń aplikacji — przekierowanie do modułu poczty (etap 4.3a).
 *
 * Strona powitalna z 4.0 była placeholderem na czas, gdy nie istniał jeszcze żaden widok;
 * dla użytkownika tej aplikacji modułem JEST poczta, więc `/` nie ma czego pokazywać.
 *
 * Trasa zostaje mimo przekierowania, bo pod `/` wchodzi się z zakładki, z adresu wpisanego
 * z palca i z menu EasyAdmina („Powrót do aplikacji"). Logowanie omija ten przeskok —
 * `default_target_path` w `security.yaml` celuje wprost w `app_mail_index`.
 */
class HomeController extends AbstractController {
    #[Route('/', name: 'app_home')]
    public function index(): Response {
        return $this->redirectToRoute('app_mail_index');
    }
}
