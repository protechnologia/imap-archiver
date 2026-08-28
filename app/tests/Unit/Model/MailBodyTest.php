<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\MailBody;
use PHPUnit\Framework\TestCase;

/**
 * `MailBody` — reguła „który wariant treści pokazać" (etap 4.5).
 *
 * Reguła mieszka w DTO, a nie w kontrolerze, więc da się ją przetestować bez kernela i bez bazy —
 * i ma dzięki temu jedną kopię, wspólną dla widoku skrzynki i dla trasy `/mail/{id}/body`.
 *
 * Najważniejszy przypadek to `testNieznanyWariantZQueryNieWywracaPodgladu()`: `?view=` jest
 * inputem użytkownika, więc wartość spoza słownika musi cicho zejść na wariant dostępny,
 * a nie wywrócić żądania ani — tym bardziej — pokazać pustego podglądu.
 */
class MailBodyTest extends TestCase {

    public function testDomyslnieWygrywaTekstGdySaObaWarianty(): void {
        $body = new MailBody(text: 'Wersja tekstowa', html: '<p>Wersja HTML</p>');

        $this->assertSame('text', $body->resolveVariant(null));
    }

    public function testZadanyWariantWygrywaGdyJestDostepny(): void {
        $body = new MailBody(text: 'Wersja tekstowa', html: '<p>Wersja HTML</p>');

        $this->assertSame('html', $body->resolveVariant('html'));
    }

    /**
     * Mail HTML-only: życzenia „tekst" nie da się spełnić, więc schodzimy na HTML — zamiast
     * pokazywać pusty podgląd wiadomości, która treść przecież ma.
     */
    public function testZadanyWariantNiedostepnySchodziNaIstniejacy(): void {
        $body = new MailBody(html: '<p>Wyłącznie HTML</p>');

        $this->assertSame('html', $body->resolveVariant('text'));
    }

    public function testNieznanyWariantZQueryNieWywracaPodgladu(): void {
        $body = new MailBody(text: 'Wersja tekstowa');

        $this->assertSame('text', $body->resolveVariant('../../etc/passwd'));
        $this->assertSame('text', $body->resolveVariant(''));
    }

    /**
     * Brak `.eml` w archiwum — nie ma czego pokazać i nie ma czego wybierać. Podgląd musi
     * dostać `null`, a nie nazwę wariantu, którego treść jest pusta.
     */
    public function testBrakObuWariantowDajeNull(): void {
        $body = new MailBody();

        $this->assertNull($body->resolveVariant('html'));
        $this->assertTrue($body->isEmpty());
    }

    public function testHasOdpowiadaTylkoNaZnaneNazwyWariantow(): void {
        $body = new MailBody(text: 'Treść');

        $this->assertTrue($body->has('text'));
        $this->assertFalse($body->has('html'));
        $this->assertFalse($body->has('markdown'), 'Nieznana nazwa wariantu nie może udawać dostępnej');
    }

    public function testPustyMailBodyNieJestUznanyZaNiepusty(): void {
        $this->assertFalse((new MailBody(text: 'Coś'))->isEmpty());
        $this->assertFalse((new MailBody(html: '<p>Coś</p>'))->isEmpty());
    }
}
