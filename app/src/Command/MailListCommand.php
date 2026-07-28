<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Message;
use App\Model\MessageListPage;
use App\Repository\MessageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostyka listy wiadomości (etap 4.1) — to samo zapytanie, które w 4.4 obsłuży komponent.
 *
 * Pozwala sprawdzić `MessageRepository::searchPage()` (sortowanie, paginację, filtr frazą)
 * zanim powstanie jakikolwiek widok, i zostaje jako narzędzie do debugowania listy — analogicznie
 * do `app:imap:ping` z etapu 2. UWAGA: komenda NIE sprawdza uprawnień (konta podaje operator
 * wprost); autoryzacją zajmuje się `MessageVoter` w warstwie HTTP (etap 4.2).
 *
 * Czysty odczyt — nie rusza bazy ani archiwum. Wypisuje nagłówek (co poszło do zapytania i co
 * odpowiedziała paginacja) oraz tabelę strony wyników; przy zerze trafień zamiast tabeli leci
 * „Brak wiadomości do pokazania.". Numer strony w nagłówku to ten FAKTYCZNIE zwrócony, po
 * przycięciu do zakresu — `--page=9` przy dwóch stronach pokaże „2 z 2".
 *
 * Przykładowe wyjście (`--account=67 --per-page=2`):
 *
 *   Konta       67
 *   Fraza       —
 *   Trafienia   3
 *   Strona      1 z 2 (po 2)
 *
 *   ID   Data               Od                    Temat                 Rozmiar (B)   OK
 *   3    2026-06-16 10:15   anna@example.com      Test z załącznikiem   49189         tak
 *   2    2026-06-16 07:58   anna@example.com      Test                  1605          tak
 *
 * Kolumna „Rozmiar (B)" celowo pokazuje surowe bajty (a nie „48,0 KB" jak EasyAdmin) — wartość
 * ma się dać porównać wprost z `ls -l` i rozmiarem pliku `.eml`. „OK" to flaga `verified`.
 *
 * Kody wyjścia: 0 przy powodzeniu, TAKŻE przy zerze trafień (pusty wynik to poprawna odpowiedź,
 * nie błąd); 1 wyłącznie przy złym wejściu — brak `--account` albo wartość niebędąca liczbą.
 */
#[AsCommand(
    name: 'app:mail:list',
    description: 'Diagnostyka etapu 4.1: wypisuje stronę listy wiadomości z searchPage() (sortowanie, paginacja, filtr).',
)]
class MailListCommand extends Command {
    /**
     * __construct
     */
    public function __construct(
        private readonly MessageRepository $messageRepository,
    ) {
        parent::__construct();
    }

    /**
     * configure
     */
    protected function configure(): void {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'ID konta MailAccount (można podać wielokrotnie)')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Fraza filtrująca (temat/nadawca/treść)')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Numer strony (od 1)', '1')
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Rozmiar strony', '20');
    }

    /**
     * execute
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $accountIds = $this->parseAccountIds($input, $io);
        if ($accountIds === null) {
            return Command::FAILURE;
        }

        $query = $this->parseQuery($input);
        $page = $this->messageRepository->searchPage(
            $accountIds,
            $query,
            (int) $input->getOption('page'),
            (int) $input->getOption('per-page'),
        );

        $this->renderSummary($io, $accountIds, $query, $page);
        $this->renderMessages($io, $page);

        return Command::SUCCESS;
    }

    /**
     * Czyta i waliduje `--account` (opcja powtarzalna).
     *
     * @param InputInterface $input Wejście komendy
     * @param SymfonyStyle   $io    Wyjście (tu trafia komunikat błędu)
     *
     * @return list<int>|null ID kont, np. [67, 68]; null gdy wejście jest niepoprawne
     */
    private function parseAccountIds(InputInterface $input, SymfonyStyle $io): ?array {
        $accountIds = [];

        foreach ((array) $input->getOption('account') as $value) {
            if (!ctype_digit((string) $value)) {
                $io->error(sprintf('--account musi być liczbą całkowitą, dostałem "%s".', $value));

                return null;
            }
            $accountIds[] = (int) $value;
        }

        if ($accountIds === []) {
            $io->error('Podaj co najmniej jedno --account=<ID>.');

            return null;
        }

        return $accountIds;
    }

    /**
     * Czyta `--query`; brak opcji i pusty string traktujemy tak samo (brak filtra).
     *
     * @param InputInterface $input Wejście komendy
     *
     * @return string|null Fraza, np. "faktura", albo null gdy nie filtrujemy
     */
    private function parseQuery(InputInterface $input): ?string {
        $query = trim((string) $input->getOption('query'));

        return $query === '' ? null : $query;
    }

    /**
     * Wypisuje nagłówek: czego szukaliśmy i co odpowiedziała paginacja.
     *
     * @param SymfonyStyle    $io         Wyjście komendy
     * @param list<int>       $accountIds ID kont użyte w zapytaniu, np. [67]
     * @param string|null     $query      Użyta fraza albo null
     * @param MessageListPage $page       Wynik `searchPage()`
     */
    private function renderSummary(SymfonyStyle $io, array $accountIds, ?string $query, MessageListPage $page): void {
        $io->definitionList(
            ['Konta' => implode(', ', $accountIds)],
            ['Fraza' => $query ?? '—'],
            ['Trafienia' => (string) $page->total],
            ['Strona' => sprintf('%d z %d (po %d)', $page->page, $page->pages, $page->perPage)],
        );
    }

    /**
     * Wypisuje tabelę wiadomości albo informację o pustym wyniku.
     *
     * @param SymfonyStyle    $io   Wyjście komendy
     * @param MessageListPage $page Wynik `searchPage()`
     */
    private function renderMessages(SymfonyStyle $io, MessageListPage $page): void {
        if ($page->items === []) {
            $io->text('Brak wiadomości do pokazania.');

            return;
        }

        $io->table(
            ['ID', 'Data', 'Od', 'Temat', 'Rozmiar (B)', 'OK'],
            array_map($this->toRow(...), $page->items),
        );
    }

    /**
     * Zamienia wiadomość w wiersz tabeli.
     *
     * Brak `Date` pokazujemy jawnie, a nie pustą komórką — te maile lądują na końcu listy
     * (`NULLS LAST` w `searchPage()`) i chcemy widzieć, że to nie błąd sortowania.
     *
     * @param Message $message Wiadomość z indeksu
     *
     * @return list<string> Komórki wiersza, np. ["12", "2026-06-16 07:58", "biuro@example.com", …]
     */
    private function toRow(Message $message): array {
        return [
            (string) $message->getId(),
            $message->getDate()?->format('Y-m-d H:i') ?? '— (brak Date)',
            $this->truncate($message->getFromEmail() ?? '', 32),
            $this->truncate($message->getSubject() ?? '', 50),
            (string) $message->getSize(),
            $message->isVerified() ? 'tak' : 'nie',
        ];
    }

    /**
     * Skraca wartość do szerokości kolumny (bez rozwalania znaków wielobajtowych).
     *
     * @param string $value  Wartość, np. "Faktura VAT 12/2026 za usługi hostingowe"
     * @param int    $length Maksymalna długość, np. 50
     *
     * @return string Wartość ucięta z wielokropkiem albo bez zmian, np. "Faktura VAT 12/2026 za usługi…"
     */
    private function truncate(string $value, int $length): string {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1) . '…';
    }

}
