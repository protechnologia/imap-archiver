<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Dwa warianty treści jednej wiadomości, wyprowadzone z `.eml` (etap 4.5).
 *
 * `multipart/alternative` niesie tę samą treść dwa razy: raz jako `text/plain`, raz jako
 * `text/html`. To NIE są różne dane, tylko dwa przedstawienia — stąd jeden DTO z dwoma polami
 * zamiast dwóch osobnych odczytów. Każde z nich bywa `null` i to jest normalny stan, nie błąd:
 * mail transakcyjny często ma wyłącznie HTML, a wiadomość systemowa wyłącznie tekst.
 *
 * DLACZEGO OBA NARAZ, skoro podgląd pokazuje jeden: przełącznik tekst/HTML musi wiedzieć, czy
 * drugi wariant w ogóle istnieje (inaczej nie da się go zablokować na dostępnej pozycji).
 * Jedno parsowanie `.eml` odpowiada na oba pytania — treść i dostępność.
 *
 * `html` jest tu SUROWY, prosto od nadawcy. Sanityzacja należy do warstwy, która go wypuszcza
 * (`MailController::body()`), nie do nośnika — DTO nie może dawać złudzenia, że coś już
 * zabezpieczył.
 */
readonly class MailBody {

    public function __construct(
        /** Część `text/plain`, np. "Cześć, w załączniku przesyłam fakturę…" albo null. */
        public ?string $text = null,

        /** Część `text/html` — SUROWA, przed sanityzacją, np. "<p>Cześć…</p>" albo null. */
        public ?string $html = null,
    ) {
    }

    /**
     * Czy wiadomość ma cokolwiek do pokazania w podglądzie.
     *
     * @return bool false, gdy nie ma ani tekstu, ani HTML-a (np. brak `.eml` i pusty indeks)
     */
    public function isEmpty(): bool {
        return $this->text === null && $this->html === null;
    }

    /**
     * Czy istnieje wariant o podanej nazwie — wprost pod przełącznik w podglądzie.
     *
     * @param string $variant Nazwa wariantu, "text" albo "html"
     *
     * @return bool true, gdy wariant da się wyświetlić
     */
    public function has(string $variant): bool {
        return match ($variant) {
            'text'  => $this->text !== null,
            'html'  => $this->html !== null,
            default => false,
        };
    }

    /**
     * Rozstrzyga, który wariant faktycznie pokazać: życzenie użytkownika przycięte do dostępnych.
     *
     * Reguła mieszka tutaj, a nie w kontrolerze, bo opiera się wyłącznie na zawartości tego DTO —
     * i dzięki temu ma jedną kopię, wspólną dla widoku skrzynki i dla trasy `/mail/{id}/body`.
     * `?view=` jest INPUTEM UŻYTKOWNIKA: wartość spoza słownika albo wskazująca nieistniejący
     * wariant nie może wywalić podglądu, tylko cicho schodzi na to, co jest.
     *
     * Domyślnie TEKST, gdy istnieje — bezpieczniejszy (nie uruchamia ścieżki sanityzacji ani
     * drugiego żądania) i wystarczający do przejrzenia archiwum. HTML jest świadomym wyborem.
     *
     * @param string|null $requested Żądany wariant z `?view=`, np. "html"; null gdy nie podano
     *
     * @return string|null "text", "html" albo null, gdy nie ma czego pokazać
     */
    public function resolveVariant(?string $requested): ?string {
        if ($requested !== null && $this->has($requested)) {
            return $requested;
        }

        return match (true) {
            $this->text !== null => 'text',
            $this->html !== null => 'html',
            default              => null,
        };
    }
}
