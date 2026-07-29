<?php

declare(strict_types=1);

/**
 * Konfiguracja php-cs-fixer — egzekwuje konwencje kodu opisane w CLAUDE.md.
 *
 * Zakres: `src/` i `tests/` (nasz kod). Boilerplate Symfony w `config/`, `public/` i `bin/`
 * celowo zostawiamy w spokoju — nie formatujemy cudzych plików startowych.
 *
 * Użycie:
 *   composer cs       — pokazuje, co wymaga poprawy (nic nie zmienia)
 *   composer cs:fix   — poprawia pliki w miejscu
 */
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
;

return (new PhpCsFixer\Config())
    // Wymagane przez `declare_strict_types` (reguła oznaczona jako „risky").
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,

        // WYJĄTEK od PSR-12 (decyzja projektu): klamra otwierająca klasy i metod
        // zostaje w TEJ SAMEJ linii co sygnatura, nie przenosimy jej do nowej.
        'braces_position' => [
            'classes_opening_brace' => 'same_line',
            'functions_opening_brace' => 'same_line',
        ],

        // Konwencja: `declare(strict_types=1)` w każdym pliku PHP.
        'declare_strict_types' => true,

        // Nieużywane `use` — PSR-12 ich nie rusza (są poprawnie sformatowane), a bez statycznej
        // analizy nikt inny by ich nie zauważył. Reguła jest bezpieczna (nie „risky").
        'no_unused_imports' => true,

        // Styl domu: natywne funkcje BEZ wiodącego `\` (`count()`, nie `\count()`).
        // Reguła dokleja backslashe, więc trzymamy ją wyłączoną świadomie.
        'native_function_invocation' => false,
    ])
    ->setFinder($finder)
;
