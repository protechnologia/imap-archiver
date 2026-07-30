<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Fixtures\EntityFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Korzeń aplikacji `/` (etap 4.3a).
 *
 * Strona powitalna zniknęła razem z placeholderem z 4.0 — `/` tylko przekierowuje do poczty.
 * Trasa zostaje, bo pod korzeń wchodzi się z zakładki, z adresu wpisanego z palca i z menu
 * EasyAdmina („Powrót do aplikacji"); ten test pilnuje, żeby te trzy wejścia nie zaczęły
 * kiedyś dawać 404.
 */
class HomeControllerTest extends WebTestCase {
    public function testKorzenPrzekierowujeDoPoczty(): void {
        $client = self::createClient();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = EntityFactory::user('user@example.com');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/');

        $this->assertResponseRedirects('/mail');
    }

    /**
     * Niezalogowany nie ma prawa poznać nawet celu przekierowania — firewall wyprzedza akcję.
     */
    public function testNiezalogowanyNaKorzeniuIdzieDoLogowania(): void {
        $client = self::createClient();
        $client->request('GET', '/');

        $this->assertResponseRedirects('http://localhost/login');
    }
}
