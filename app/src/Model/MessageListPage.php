<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Message;

/**
 * Jedna strona listy wiadomości (etap 4.1).
 *
 * DTO wyniku `MessageRepository::searchPage()`: niesie rekordy razem z kontekstem paginacji,
 * żeby komponent listy nie musiał sam dopytywać o `COUNT`. Kontrakt jest celowo taki, jaki
 * potrafi zwrócić silnik wyszukiwania (trafienia + łączna liczba trafień), bo w etapie 7
 * wnętrze metody podmieniamy na Meilisearch — ten sam DTO, inne źródło.
 *
 * `page` to strona FAKTYCZNIE zwrócona: repozytorium przycina żądany numer do zakresu
 * (patrz `searchPage()`), więc może różnić się od tego, o który prosił użytkownik.
 */
readonly class MessageListPage {
    /**
     * @param list<Message> $items Wiadomości na tej stronie, posortowane malejąco po dacie
     */
    public function __construct(
        /** @var list<Message> Rekordy strony (pusta lista, gdy brak trafień). */
        public array $items,

        /** Łączna liczba trafień w całym zapytaniu (nie na stronie), np. 1284. */
        public int $total,

        /** Numer zwróconej strony, liczony od 1, np. 3. */
        public int $page,

        /** Rozmiar strony użyty w zapytaniu, np. 50. */
        public int $perPage,

        /** Liczba stron przy tym `perPage`; 1 nawet przy zerowej liczbie trafień, np. 26. */
        public int $pages,
    ) {
    }

}
