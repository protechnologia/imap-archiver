<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Profil sanitizera `mail.body` — PIERWSZA z trzech zapór podglądu (etap 4.5).
 *
 * Integracyjnie, bo przedmiotem testu jest KONFIGURACJA (`config/packages/html_sanitizer.yaml`)
 * złożona przez kontener, a nie kod, który dałoby się wywołać w izolacji. Literówka w kluczu
 * konfiguracji albo skasowana linia to awaria całkowicie cicha: sanitizer dalej działa, tylko
 * przepuszcza więcej — i żaden test kodu tego nie zauważy.
 *
 * Zakres jest CELOWO wąski: sprawdzamy nasze decyzje, a nie bibliotekę. To, że
 * `symfony/html-sanitizer` w ogóle usuwa `<script>`, jest jego sprawą; tutaj pilnujemy tego,
 * co ustawiliśmy sami i co da się przypadkiem cofnąć — pustej listy hostów mediów, wycinania
 * stylów inline oraz wymuszonego `target`/`rel` na linkach.
 *
 * Sanitizer NIE jest jedyną obroną i test nie udaje, że jest: skrypt, który by się przecisnął,
 * i tak nie ma jak się wykonać w `<iframe sandbox>` bez `allow-scripts` ani zażądać czegokolwiek
 * przy `default-src 'none'`. Podział ról opisuje docblok `MailController::body()`.
 */
class HtmlSanitizerMailBodyTest extends KernelTestCase {

    private HtmlSanitizerInterface $sanitizer;

    protected function setUp(): void {
        self::bootKernel();
        $this->sanitizer = self::getContainer()->get('html_sanitizer.sanitizer.mail.body');
    }

    public function testSkryptZnikaRazemZTrescia(): void {
        $wynik = $this->sanitizer->sanitize('<p>Przed</p><script>alert(1)</script><p>Po</p>');

        $this->assertStringNotContainsString('script', $wynik);
        $this->assertStringNotContainsString('alert(1)', $wynik, 'Skrypt ma zniknąć RAZEM z zawartością, nie zostać jako tekst');
        $this->assertStringContainsString('Przed', $wynik);
        $this->assertStringContainsString('Po', $wynik);
    }

    public function testAtrybutyZdarzenNiePrzechodza(): void {
        $wynik = $this->sanitizer->sanitize('<img src="https://example.com/a.png" onerror="alert(1)" alt="Logo">');

        $this->assertStringNotContainsString('onerror', $wynik);
    }

    /**
     * Pusta lista hostów mediów (`allowed_media_hosts: []`) — piksel śledzący traci adres już
     * na serwerze. CSP zablokowałoby żądanie i tak; to celowa nadmiarowość, więc gdyby ktoś
     * poluzował nagłówek, obrazy dalej nie wyciekną.
     */
    public function testZdalnyObrazTraciAdres(): void {
        $wynik = $this->sanitizer->sanitize('<img src="https://tracking.example/pixel.gif?id=42" alt="">');

        $this->assertStringNotContainsString('tracking.example', $wynik);
        $this->assertStringNotContainsString('src=', $wynik);
    }

    /**
     * Styl inline leci w całość, bo sanitizer nie ma parsera CSS i nie odróżniłby typografii
     * od `position: fixed` przykrywającego interfejs. Mail dostaje naszą typografię z `body.html.twig`.
     */
    public function testStylInlineJestUsuwany(): void {
        $wynik = $this->sanitizer->sanitize('<p style="position:fixed;top:0">Treść</p>');

        $this->assertStringNotContainsString('style', $wynik);
        $this->assertStringContainsString('Treść', $wynik);
    }

    public function testLinkJavascriptTraciAdres(): void {
        $wynik = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Kliknij</a>');

        $this->assertStringNotContainsString('javascript:', $wynik);
        $this->assertStringContainsString('Kliknij', $wynik, 'Napis linku to treść maila — ma zostać');
    }

    /**
     * Wymuszony `target="_blank"`: bez tego klik nawigowałby SAM IFRAME i cudza strona wjechałaby
     * do kolumny podglądu, udając część aplikacji. `rel` odcina dostęp do `window.opener`
     * i nie zdradza nadawcy, że ktoś czyta jego mail.
     */
    public function testLinkiSaWypychaneDoNowejKarty(): void {
        $wynik = $this->sanitizer->sanitize('<a href="https://example.com/faktura">Faktura</a>');

        $this->assertStringContainsString('target="_blank"', $wynik);
        $this->assertStringContainsString('noopener', $wynik);
        $this->assertStringContainsString('noreferrer', $wynik);
    }

    public function testFormularzZnikaAleJegoTrescZostaje(): void {
        $wynik = $this->sanitizer->sanitize('<form action="https://phishing.example"><p>Zaloguj się</p></form>');

        $this->assertStringNotContainsString('<form', $wynik);
        $this->assertStringNotContainsString('phishing.example', $wynik);
        $this->assertStringContainsString('Zaloguj się', $wynik, 'Tekst między znacznikami formularza to treść maila');
    }

    /**
     * Sanityzacja nie może zjadać tego, PO CO w ogóle renderujemy HTML — tabeli z pozycjami
     * faktury nie da się oddać w części tekstowej.
     */
    public function testStrukturaTresciPrzezywaSanityzacje(): void {
        $wynik = $this->sanitizer->sanitize(
            '<h1>Faktura</h1><table><tr><td>Usługa</td><td>1 230,00 zł</td></tr></table><strong>Razem</strong>',
        );

        $this->assertStringContainsString('<h1>', $wynik);
        $this->assertStringContainsString('<table>', $wynik);
        $this->assertStringContainsString('1 230,00 zł', $wynik);
        $this->assertStringContainsString('<strong>', $wynik);
    }
}
