<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\ByteFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtr Twiga `|bytes` — rozmiar w bajtach na czytelną jednostkę. Etap 3.4.
 *
 * Cienka fasada nad `ByteFormatter`, żeby szablony formatowały rozmiary DOKŁADNIE tak samo jak
 * kod PHP (`formatValue()` pól EasyAdmin) — bez duplikowania logiki w Twigu.
 *
 * Użycie: `{{ attachment.size|bytes }}` → "11,8 KB".
 */
final class ByteExtension extends AbstractExtension {
    /**
     * @return list<TwigFilter> Filtry wystawiane szablonom
     */
    public function getFilters(): array {
        return [
            new TwigFilter('bytes', ByteFormatter::humanize(...)),
        ];
    }
}
