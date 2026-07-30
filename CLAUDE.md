# imap-archiver

Aplikacja do archiwizacji poczty z serwera IMAP. Pobiera wiadomości (np. z konkretnego
roku), zapisuje surowe `.eml` jako źródło prawdy, indeksuje je w bazie do podglądu i
wyszukiwania, i opcjonalnie kasuje z serwera po świadomym potwierdzeniu admina.
Wielu użytkowników z dostępem do podglądu; jeden admin robi import i zarządza kontami.

## Konwencje

- Komunikacja, UI i treści po polsku; kod, identyfikatory i nazwy plików po angielsku.
- `declare(strict_types=1)` w każdym pliku PHP.
- **PSR-12 z jednym świadomym wyjątkiem: klamra otwierająca klasy i metody zostaje w TEJ SAMEJ
  linii** co sygnatura (PSR-12 każe ją przenosić do nowej — u nas nie).
- **Natywne funkcje bez wiodącego `\`** (`count()`, nie `\count()`); globalne KLASY zachowują `\`
  (`\DateTimeImmutable`, `\RuntimeException`). Formatter IDE lubi doklejać backslashe — wyłączyć.
- Docbloki po polsku: akapit „dlaczego" (decyzja/pułapka) + `@param`/`@return` z konkretnym
  przykładem wartości („np. 49189").
- **Styl egzekwuje php-cs-fixer** (`app/.php-cs-fixer.dist.php`, zakres `src/` + `tests/`): `composer cs`
  sprawdza, `composer cs:fix` poprawia. Boilerplate Symfony (`config/`, `public/`, `bin/`) poza
  zakresem — nie formatujemy cudzych plików startowych.
- To jest IMAP, **nie SMTP** (SMTP służy tylko do wysyłki).

## Stack

- PHP 8.x / Symfony 7.4 (LTS)
- FrankenPHP (serwer + worker mode na produkcji), Caddy
- PostgreSQL 16
- IMAP: `webklex/php-imap` (NIE `ext-imap` — wypada z core PHP)
- Symfony Messenger (transport Doctrine na start; AMQP/RabbitMQ później)
- Front: Twig + Symfony UX (Turbo + Stimulus + Live Components), Tailwind przez
  `symfonycasts/tailwind-bundle`, AssetMapper — bez Node/npm
- Panel admina: EasyAdmin
- Mercure (wbudowany w FrankenPHP) — live-progress importu
- Docker / docker compose
- Później: Meilisearch (full-text search, etap 7)

## Architektura — zasady, których trzymamy się bezwzględnie

- **Rozdział archiwum i indeksu.** Surowe `.eml` na wolumenie = ŹRÓDŁO PRAWDY,
  niemutowalne. Baza = indeks + sparsowana treść do podglądu i szukania. Bazę da się
  odbudować z archiwum, nigdy odwrotnie.
- **Pipeline importu:** IMAP `SEARCH` po dacie → pobranie surowego `.eml` (`BODY.PEEK[]`) → `sha256`
  → idempotencja po DB (jest `UNIQUE(account_id, sha256)`? pomiń) → zapis `.eml` → indeks
  `Message`/`Attachment` wyprowadzony z TYCH SAMYCH bajtów → weryfikacja (odczyt z dysku + przeliczony
  `sha256`) → `verified`. Idempotentnie po **checksumie treści**, nie po `Message-ID` (patrz Model danych).
- **Import zawsze asynchronicznie** (Messenger), nigdy w żądaniu HTTP.
- **Bezpieczne usuwanie (KRYTYCZNE).** Kasować wolno TYLKO wiadomości `verified`
  (są w DB + jest plik `.eml` + checksum się zgadza). Przepływ: import → weryfikacja →
  podsumowanie dla admina → świadome potwierdzenie → `\Deleted` + `EXPUNGE`
  (bezpieczniej: najpierw przenieść do `Archived/`). Nigdy automatycznie. Log audytowy usunięć.
- **Dostęp:** `ROLE_ADMIN` / `ROLE_USER`, mapowanie many-to-many `User ↔ MailAccount`,
  Voter na poziomie podglądu wiadomości. **`MessageVoter` NIE ma skrótu dla `ROLE_ADMIN`** —
  na froncie admin jest zwykłym czytelnikiem swojej poczty i dostaje 403 na cudzej. Rola opisuje
  funkcję administracyjną (konta, import, kasowanie z etapu 6), nie prawo do cudzej korespondencji;
  panel EA pokazuje wyłącznie metadane indeksu, treści tam nie ma. Admin, który musi przeczytać
  cudzą skrzynkę, przypisuje sobie konto w panelu — jawny wpis w `mail_account_user` zamiast
  niewidocznego obejścia w kodzie. Uprawnienie do KASOWANIA (etap 6) to osobna sprawa
  (`ROLE_ADMIN`), nie rozszerzenie `VIEW`.

## Model danych

- `User`, `MailAccount`, `Message`, `Attachment`.
- `Message`: account, folder, messageId, subject, fromName, fromEmail, date (kolumna `sent_at` —
  unikamy słowa kluczowego `date`), size, sha256, hasAttachments, verified, body, imapUid,
  archivePath (ścieżka względna do `.eml`).
- `Attachment`: message, filename, mimeType, size, sha256 — **tylko metadane**; bajty zostają
  w `.eml`, nie duplikujemy ich na dysk.
- **Tożsamością treści jest `sha256` surowego `.eml`, NIE `messageId`.** Stąd unikat
  `UNIQUE(account_id, sha256)`: `message_id` bywa NULL (w Postgresie `NULL ≠ NULL`, więc nie nadaje
  się na klucz), a `sha256` jest zawsze obecny. `message_id` zostaje nullable + zindeksowany do
  cross-referencji. Indeksy: `(account_id, sent_at)` pod listę, `message_id`.
- **FK:** `Attachment → Message` z `ON DELETE CASCADE` (metadane giną z wiadomością);
  `Message → MailAccount` **bez** cascade — celowy guard: nie skasujesz konta z zarchiwizowaną pocztą.
  M2M `User ↔ MailAccount` przez `mail_account_user` (`ON DELETE CASCADE`).
- **Układ archiwum:** `<accountId>/<rok>/<mm>/<sha256>.eml` (rok/mm wg INTERNALDATE). Nazwa pliku
  = `sha256` → naturalna deduplikacja i weryfikowalność; zapis atomowy (temp + rename).
- Poświadczenia IMAP: szyfrowane at-rest, NIGDY plaintext. Pole na typ uwierzytelniania
  (hasło vs OAuth2/XOAUTH2).

## Front — wzorzec

- **Turbo Frames** = nawigacja między regionami (folder → lista, wiadomość → podgląd).
- **Live Component `MailList`** = reaktywność wewnątrz listy (szukaj/filtr/paginacja jako
  `LiveProp`). Zagnieżdżony WEWNĄTRZ ramki listy — nie nakładamy obu mechanizmów na ten
  sam element.
- **Stimulus** = drobne sprinkles (nawigacja klawiaturą, obsługa iframe).
- Treść HTML maila renderujemy w `<iframe sandbox>` (bez `allow-scripts`), po przepuszczeniu
  przez HTMLPurifier.
- `LiveProp` non-writable są podpisane checksumem z `APP_SECRET` → można im ufać przy
  zawężaniu zapytań. Writable (`query`, `page`) traktujemy jak input użytkownika.
- Szew na full-text: filtr listy idzie przez `MessageRepository::searchPage()`
  (na start `LIKE`). Na etapie 7 podmieniamy wnętrze tej metody na Meilisearch bez
  ruszania komponentu i szablonu.

## Testy — zasady

Zestaw wystartował przy etapie 4.2 (PHPUnit + `symfony/test-pack` + `dama/doctrine-test-bundle`).

- **Kontrolery tylko funkcjonalnie (`WebTestCase`), nigdy jednostkowo.** W akcjach nie ma logiki
  do izolowania, a wszystko, co w nich ryzykowne, mieszka POZA klasą: `#[IsGranted]`/`#[CurrentUser]`
  rozwiązuje framework, 403 wychodzi z warstwy security, 404 na `/mail/abc` daje routing
  (`Requirement::DIGITS`), a błąd w Twigu to 500. Test z zamockowanym `render()` nie zobaczy nic z tego.
- **Voter: jednostkowo + dwa razy funkcjonalnie.** Jednostkowo pełna matryca reguły na podstawionym
  repozytorium (grosze czasu), funkcjonalnie tylko potwierdzenie, że Voter jest PODPIĘTY — bo
  najgroźniejsza awaria to nie zła reguła, lecz reguła, o którą nikt nie pyta.
- **Repozytoria integracyjnie i KONIECZNIE na Postgresie.** `searchPage()` testuje zachowania silnika
  (`NULLS FIRST` przy `DESC`, `LIKE` wrażliwy na wielkość liter, `ESCAPE`); na SQLite „dla szybkości"
  te testy świeciłyby na zielono przy zepsutym kodzie.
- **Dane testowe budujemy w kodzie** (`tests/Fixtures/EntityFactory.php`), bez `DoctrineFixturesBundle`
  — każdy test potrzebuje 2-3 rekordów ustawionych pod konkretny przypadek, nie wspólnego zestawu.
  `EntityFactory::withId()` wstawia ID refleksją WYŁĄCZNIE dla testów bez bazy (encje nie mają
  settera `id`). Statyczne dane (surowe `.eml`) leżą obok, w `tests/Fixtures/eml/` — `tests/Fixtures/`
  to jedno miejsce na wszystko, z czego testy czerpią dane; klasa nazywa się `EntityFactory`, a nie
  `Fixtures`, żeby nie dublować nazwy katalogu.
- **`dama/doctrine-test-bundle`** owija każdy test w transakcję cofaną na końcu — testy nie widzą
  swoich danych nawzajem i nie sprzątają ręcznie. Recipe jest w `recipes-contrib`, więc rejestracja
  bundla (`config/bundles.php`) i rozszerzenia PHPUnit (`phpunit.dist.xml`) jest ROBIONA RĘCZNIE.
- Baza testowa to `app_test` — robi ją `dbname_suffix: _test` z `when@test` w `doctrine.yaml`,
  `DATABASE_URL` zostaje ten z `compose.yaml`. Sekrety testowe (`APP_SECRET`, `MAIL_CRYPTO_KEY`)
  w commitowanym `app/.env.test`; bez `MAIL_CRYPTO_KEY` nie zapiszesz `MailAccount`.
- Zakres php-cs-fixer obejmuje `tests/` na równi z `src/`.

## Plan inkrementalny (status)

Każdy etap kończy się czymś działającym. Niebezpieczne usuwanie (etap 6) podłączamy
dopiero, gdy import, archiwum i weryfikacja są pewne.

### Zrealizowane (0–3) — stan „co już działa"

Szczegóły decyzji i weryfikacji są w historii gita; tu tylko efekt. Trwałe ustalenia z tych etapów
mieszkają w sekcjach **Architektura**, **Model danych**, **Konfiguracja i sekrety** i **Gotchas**.

- [x] **Etap 0** — szkielet Symfony + Docker (FrankenPHP + Postgres): `compose.yaml`, `Dockerfile`,
      `frankenphp/Caddyfile`.
- [x] **Etap 1** — model danych, logowanie, EasyAdmin. Encje `User` i `MailAccount` (+ M2M
      `User ↔ MailAccount`), formularz logowania i role, CRUD userów i kont IMAP w panelu, komenda
      `app:user:create`. Poświadczenia szyfrowane at-rest custom typem Doctrine `encrypted_string`
      (libsodium, klucz `MAIL_CRYPTO_KEY`). Tylko `AuthType::Password`; XOAUTH2 czeka na implementację.
- [x] **Etap 2** — połączenie IMAP (`webklex/php-imap` v6). `ImapConnectionFactory` buduje
      połączonego klienta z `MailAccount`; komenda diagnostyczna `app:imap:ping` listuje foldery
      i nagłówki. READ-ONLY. Decyzja: własny serwer IMAP + hasło na start.
- [x] **Etap 3** — import synchroniczny (`.eml` + indeks, idempotentnie). `ImapReader` (warstwa IMAP:
      SEARCH po INTERNALDATE, pobranie przez `BODY.PEEK[]`) + `ImportManager` (polityka: idempotencja
      → zapis → indeks → weryfikacja) + `ArchiveStorage` (atomowy zapis `.eml`) + `MessageFactory`
      (encje z tych samych bajtów). Komenda `app:archive:import --account --year [--dry-run]`.
      Diagnostyczny podgląd `Message` w EasyAdmin (read-only, `index` + `detail`).
      ➜ zweryfikowane na realnym koncie: import i ponowny przebieg bez duplikatów, `verified`,
      `sha256sum` plików zgodne z nazwami.

### Do zrobienia

- [ ] **Etap 4 — podgląd dla użytkowników** (Twig/UX trójpanelowy, Voter, sandbox iframe).
  - [x] **4.0** — stack frontowy: `symfony/ux-turbo`, `symfony/stimulus-bundle`,
        `symfony/ux-live-component` (`twig-component` już był) przez AssetMapper/importmap.
        Chrome zalogowanej aplikacji wydzielony do `templates/layout.html.twig` — NIE do
        `base.html.twig`, bo z base korzysta też logowanie (nagłówek z `app.user` padłby na nullu).
        ➜ potwierdzone w przeglądarce: Turbo Drive i kontroler Stimulus działają.
  - [x] **4.1** — dostęp do danych: `MessageRepository::searchPage()` (szew pod Meilisearch —
        na start `LIKE`) + DTO `MessageListPage` + diagnostyczna komenda `app:mail:list`.
        Paginacja OFFSETOWA (nie keyset), bo Meilisearch zwraca trafienia + `total` i sortuje
        po trafności. Repozytorium nie ma trybu „wszystkie konta" — listę podaje warstwa wyżej.
        ➜ zweryfikowane: strony i przycięcie numeru poza zakresem, filtr bez względu na wielkość
        liter (też polskie znaki), `%`/`_` z frazy nie działają jak wieloznaczniki, rekord bez
        `Date` ląduje na końcu listy.
  - [x] **4.2** — `MessageVoter` (`VIEW`) po M2M `User ↔ MailAccount` + `MailController`
        (`/mail`, `/mail/{id}`) z tymczasowymi widokami (`templates/mail/`). Decyzja: **admin
        NIE widzi wszystkiego bez przypisania** — uzasadnienie w sekcji „Architektura → Dostęp".
        Zawężenie w dwóch miejscach: lista dostaje konta użytkownika (`searchPage()` nie ma trybu
        „wszystkie konta"), detal pyta Votera. Oba czerpią z JEDNEGO źródła —
        `MailAccountRepository::findForUser()` / `findIdsForUser()` — żeby reguła dostępu nie
        istniała w dwóch egzemplarzach (w 4.4 sięgnie tam też komponent `MailList`). Zapytanie
        zamiast leniwej kolekcji `User::getMailAccounts()`, bo tylko tak wymusimy `ORDER BY label`
        (stała kolejność panelu kont w 4.3). Voter porównuje konta **po ID**, nie
        `Collection::contains()` — tożsamość obiektów przestaje być pewna po `EntityManager::clear()`
        w workerze (etap 5). Kontroler nie ma metod prywatnych; użytkownik wjeżdża przez
        `#[CurrentUser] User $user` (gwarantowany przez `#[IsGranted('ROLE_USER')]` na klasie).
        ➜ zweryfikowane curl-em: przypisany user i przypisany admin 200 (3 maile na liście),
        nieprzypisany user i **nieprzypisany admin 403** na cudzym mailu i pusta lista, nieistniejące
        ID 404, `?page=999` przycięte do 200, bez logowania 302.
  - [x] **4.2b — testy warstwy usług, typów i komend.** Zasady podziału na jednostkowe /
        integracyjne / funkcjonalne są w sekcji **Testy — zasady**; szczegóły przypadków
        w dokblokach samych testów. Pokryte: `ArchiveStorage`, `ImportManager` (najważniejszy
        test w projekcie — idempotencja, `verified`, `--dry-run`, rozjazd pliku z indeksem),
        `MessageFactory` (fixtury `.eml`), `EncryptedStringType` + `CredentialEncryptor`,
        komendy `app:user:create`, `app:archive:import` i `app:mail:list`, `ByteFormatter`.
        ➜ 110 testów, 242 asercje. Testy znalazły dwa realne błędy: mail bez nagłówka `Date`
        dostawał w indeksie `1970-01-01` zamiast `null` (gotcha o pustym `Attribute::first()`),
        a niehexowy `MAIL_CRYPTO_KEY` leciał surowym `SodiumException` bez wskazania zmiennej.
        Świadomie BEZ testów: **`ImapReader`** (sedno to dialog z serwerem — test na sfingowanym
        protokole sprawdzałby nasze wyobrażenie o webkleksie; realną weryfikacją jest
        `app:imap:ping` i import na żywym koncie) oraz **`ImapConnectionFactory`** (`connect()`
        buduje konfigurację i od razu się łączy; wnioskowanie szyfrowania z portu da się
        przetestować dopiero po rozbiciu na `configFor()` + `connect()` — robimy to przy XOAUTH2,
        żeby refactor miał dwa powody, nie jeden). Modele (`ArchivedFile`, `RawMessage`,
        `ImportSummary`, `MessageListPage`) to `readonly` nośniki — nie ma czego testować.
  - [ ] **4.3** — trójpanelowy layout na Turbo Frames: konta/foldery → lista → podgląd.
        Ramka = nawigacja między regionami; bez reaktywności wewnątrz (ta wchodzi w 4.4).
        Adresowanie: **wiadomość zostaje w ścieżce** (`/mail/{id}` — tożsamość zasobu), a wybór
        konta idzie **query stringiem** (`?account=67` — stan widoku), spójnie z `page` i z tym,
        jak w 4.4 `LiveProp(url: true)` zapisuje stan komponentu.
    - [x] **4.3a — trasy i akcje, jeszcze bez ramek.** DWIE trasy obsługiwane przez JEDNĄ akcję
          (`MailController::mailbox()`): `/mail` to trzy panele bez wybranej wiadomości,
          `/mail/{id}` te same trzy panele z wypełnionym podglądem. Osobnej trasy na sam środkowy
          panel (`/mail/list`) świadomie NIE ma — Turbo wyciąga `<turbo-frame>` z odpowiedzi
          pełnej strony, więc dodatkowy adres oznaczałby drugi szablon do utrzymania i widok,
          który bez JS pokazuje goły fragment. Regiony są wydzielone do partiali
          (`_accounts`/`_list`/`_message`), więc w 4.3c wystarczy je opakować.
          **Bezpieczeństwo:** `?account=` to input użytkownika i przechodzi przez
          `MailAccountRepository::findOneForUser()` — cudze albo nieistniejące ID daje `null`
          (widok wraca do „wszystkie konta"), nigdy cudzą pocztę.
          ➜ zweryfikowane curl-em: `/mail` i `/mail?account=67` (nagłówek listy i linki niosą
          wybrane konto), `?account=999` cicho ignorowane, `/mail/2` renderuje komplet paneli
          z tematem w `h1`, 110 testów z 4.2b dalej zielonych.
    - [ ] **4.3b — layout i stany puste.** Siatka Tailwind na pełną wysokość, **osobne przewijanie
          każdej kolumny** (nie jedno dla całej strony), panel kont z `findForUser()` (stała
          kolejność po `label`), zaznaczenie aktywnego konta, sensowne pustki: brak kont, konto bez
          wiadomości, brak wybranej wiadomości. Foldery na razie płasko — `Message.folder` jest
          stringiem z importu, drzewo folderów to nie ten etap.
    - [ ] **4.3c — Turbo Frames.** Regiony w `<turbo-frame>`, linki listy celują w ramkę podglądu
          (`data-turbo-frame`), a nawigacja wychodząca poza moduł (panel admina, wylogowanie) ma
          `target="_top"` — inaczej cała aplikacja wyląduje w środkowej kolumnie. Punkt kontrolny:
          klik w wiadomość przeładowuje TYLKO prawy panel.
    - [ ] **4.3d — adres URL, wstecz i deep link.** `data-turbo-action="advance"` przy przejściu
          lista → podgląd, żeby adres się zmieniał, a `wstecz`/`odśwież` działały. Warunek konieczny:
          wejście prosto na `/mail/{id}` musi wyrenderować PEŁNE trzy panele — czyli ta sama akcja
          obsługuje dwa tryby (samodzielny i w ramce), rozpoznawane po nagłówku `Turbo-Frame`.
    - [ ] **4.3e — testy funkcjonalne pod nowy layout.** Istniejące asercje z 4.2 wiszą na treści
          `body` i po przebudowie szablonów wymagają dostrojenia. Dochodzą dwa nowe przypadki:
          żądanie ramki oddaje sam fragment (nie całą stronę), a deep link do wiadomości renderuje
          komplet paneli. Autoryzacja (403/404) ma zachowywać się identycznie w obu trybach.
  - [ ] **4.4** — Live Component `MailList` zagnieżdżony WEWNĄTRZ ramki listy: `query`/`page`
        jako writable `LiveProp` (traktowane jak input), `accountId` non-writable (podpisany).
  - [ ] **4.5** — render treści maila. UWAGA: `Message.body` to tylko ziarno tekstowe
        (`MessageFactory::extractBody()` preferuje `text`, HTML jest fallbackiem) — pełny HTML
        czytamy z `.eml` przez `ArchiveStorage`. HTMLPurifier → `<iframe sandbox>` bez
        `allow-scripts`; blokada zdalnych obrazów (pixele śledzące).
  - [ ] **4.6** — załączniki: pobieranie bajtów wyciętych z `.eml` (metadane w DB ich nie mają),
        `Content-Disposition: attachment`, sanityzacja nazwy pliku, autoryzacja przez Voter.
  - [ ] **4.7** — sprinkles Stimulus: nawigacja klawiaturą po liście, dopasowanie wysokości iframe.
- [ ] Etap 5 — async (Messenger) + skala + progress. Przy włączaniu workera przejrzeć pod kątem
      stanu żądania także serwisy dołożone w etapie 4 (komponent `MailList`, Voter).
- [ ] **Etap 5.5 — `app:doctor`: kontrola stanu instalacji po deployu.** Jedna komenda z kodem
      wyjścia ≠ 0 przy problemie, do odpalania po każdym deployu i z crona. Poprzedza etap 6
      celowo: bezpieczne kasowanie opiera się dokładnie na tych gwarancjach, które ona sprawdza.
      **Powód, dla którego to nie jest fanaberia:** archiwum to bind mount `${ARCHIVE_HOST_DIR}:/archive`.
      Gdy montowanie się nie uda albo ktoś zmieni ścieżkę na hoście, kontener utworzy `/archive`
      jako PUSTY katalog w swojej warstwie i wszystko będzie wyglądało poprawnie — import zapisze
      pliki, oznaczy `verified`, panel pokaże zielone ptaszki, a źródło prawdy poleci na
      efemeryczną warstwę i zniknie przy `down`. Baza przeżyje, indeks będzie pełen, archiwum
      puste — czyli dokładnie odwrotnie niż zakłada architektura.
  - [ ] **Plik-znacznik archiwum** (`.archive-id` z identyfikatorem instalacji) tworzony przy
        inicjalizacji na hoście. Brak znacznika = „to nie jest mój wolumen" → `ImportManager`
        odmawia pracy, zamiast pisać w próżnię. To jest sedno całego etapu; reszta to diagnostyka.
  - [ ] Sprawdzenia komendy: baza odpowiada, **migracje aktualne** (`doctrine:migrations:up-to-date`)
        i schemat bez dryfu (`doctrine:schema:validate`); `ARCHIVE_DIR` istnieje, jest zapisywalny
        i ma znacznik; liczba wierszy `Message` vs liczba plików `.eml` (rozjazd w KAŻDĄ stronę to
        alarm); próbkowa weryfikacja `sha256` N losowych wiadomości (`--all` dla pełnej);
        `MAIL_CRYPTO_KEY` odszyfrowuje istniejące poświadczenia (wykrywa rotację klucza bez re-encryptu).
  - [ ] Do rozważenia przy okazji: endpoint `/health` + `HEALTHCHECK` w `compose.yaml` (ta sama
        logika, tylko ciągła i dla monitoringu, bez części kosztownej — bez skanu archiwum).
- [ ] Etap 6 — bezpieczne usuwanie (weryfikacja + potwierdzenie + EXPUNGE + audyt).
- [ ] Etap 7 — full-text search (Meilisearch).
- [ ] Poza numeracją: **XOAUTH2** (Gmail / M365). `AuthType::Xoauth2` już jest w enumie, ale
      `ImapConnectionFactory` świadomie odrzuca go wyjątkiem — do zrobienia, gdy pojawi się
      potrzeba dostawcy z OAuth2.

## Polecenia (dev)

```bash
docker compose up -d --build
docker compose exec php php bin/console <komenda>
docker compose exec php composer <komenda>
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose exec php php bin/console dbal:run-sql "SELECT 1"   # smoke test DB

# użytkownicy (od etapu 1)
docker compose exec php php bin/console app:user:create <email> [hasło] [--admin]
# konto testowe (dev) założone w etapie 1.1: admin@example.com / Tajne123! (ROLE_ADMIN)

# front: build CSS Tailwind (od etapu 1; v4, bez Node)
docker compose exec php php bin/console tailwind:build [-m] [--watch]

# testy (od etapu 4.2)
docker compose exec php composer test:db   # baza app_test + migracje (raz, i po nowej migracji)
docker compose exec php composer test      # cały zestaw
docker compose exec php php bin/phpunit tests/Unit          # tylko jednostkowe (bez bazy)
docker compose exec php php bin/phpunit --filter Voter      # wybrany przypadek

# styl kodu (php-cs-fixer; zakres src/ + tests/)
docker compose exec php composer cs        # tylko pokazuje, co wymaga poprawy
docker compose exec php composer cs:fix    # poprawia w miejscu

# spike połączenia IMAP (od etapu 2) — read-only, listuje foldery + nagłówki najnowszych
docker compose exec php php bin/console app:imap:ping --account=<id> [--limit=N]

# import (od etapu 3)
docker compose exec php php bin/console app:archive:import --account=<id> --year=<rok>

# diagnostyka listy wiadomości (od etapu 4.1) — to samo zapytanie, co komponent listy
docker compose exec php php bin/console app:mail:list --account=<id> [--query=fraza] [--page=N] [--per-page=N]

# konsument kolejki (od etapu 5)
docker compose exec php php bin/console messenger:consume async -vv
```

Aplikacja w dev: `http://localhost:8180` (FrankenPHP na `SERVER_NAME=":80"`, port 8180→80).

Projekt mieszka w **natywnym FS WSL**: `~/projects/imap-archiver` (`/root/projects/imap-archiver`
w dystrybucji `dev-edor-gw`), **nie** na `/mnt/c`. Edycja przez VS Code Remote-WSL; dostęp z
Eksploratora Windows pod `\\wsl.localhost\dev-edor-gw\root\projects\imap-archiver`.

## Konfiguracja i sekrety

- **Dwie warstwy `.env` — różne pliki, różni konsumenci.**
  - **Warstwa Symfony:** `app/.env*` czyta Symfony (Dotenv, kolejność `.env` → `.env.$APP_ENV`
    → `.env.local`). Sekrety aplikacji (`APP_SECRET`, `MAIL_CRYPTO_KEY`): jawna wartość **dev**
    w commitowanym `app/.env.dev` (zero-config, dev używa atrap), realna wartość **prod** w
    gitignorowanym `app/.env.local`; w `app/.env` pusty placeholder. `DATABASE_URL` z `compose.yaml`
    (real env var) NADPISUJE ten z `app/.env` — wartość w `app/.env` (host `127.0.0.1`) to tylko
    atrapa dla trybu non-Docker.
  - **Warstwa Compose:** root-owy `/.env` (obok `compose.yaml`) czyta **tylko** Docker Compose
    do podstawiania `${...}` — jeden plik, bez hierarchii, gitignorowany (szablon w commitowanym
    `.env.dist`). Sekret bazy (`DB_PASSWORD`, konsumowany przez kontener Postgresa **i** aplikację)
    → root `/.env`; default dev jest inline w `compose.yaml` jako `${DB_PASSWORD:-ChangeMe}`.
- `APP_SECRET` trzymamy w sekrecie i nie rotujemy bez powodu (unieważnia podpisy Live Components).
- **`MAIL_CRYPTO_KEY` (32 B hex) szyfruje `MailAccount.secret`** (libsodium `secretbox`). Klucz dev
  ≠ prod. NIE rotować bez re-encryptu istniejących sekretów — stare szyfrogramy stają się
  nieodczytywalne (`secretbox_open` zwróci false → wyjątek). Utrata klucza = utrata poświadczeń.
- **`secret` w formularzu EasyAdmin nigdy nie jest mapowany wprost.** `MailAccountCrudController`
  renderuje go jako `PasswordType` z `mapped=false`, `onlyOnForms()`, a listener `POST_SUBMIT`
  przepisuje plaintext na encję (typ `encrypted_string` szyfruje przy zapisie). **Puste pole przy
  edycji = hasło bez zmiany** — nie nadpisujemy istniejącego szyfrogramu. Nigdy nie pokazujemy
  wartości na liście ani w podglądzie.
- **`IMAP_VALIDATE_CERT` to NIE sekret, tylko flaga zachowania** (warstwa Symfony). Jawny default
  `1` w commitowanym `app/.env` (prod-safe: wymagaj poprawnego cert). Wyłączamy do `0` w
  gitignorowanym `app/.env.local` tylko przy serwerze IMAP z self-signed cert w dev. Konsumuje ją
  `ImapConnectionFactory` (`%env(bool:IMAP_VALIDATE_CERT)%`).
- **`POSTGRES_PASSWORD` działa TYLKO przy pierwszej inicjalizacji wolumenu `database_data`.**
  Zmiana `DB_PASSWORD` przy istniejącej bazie NIE zmieni hasła już utworzonego Postgresa —
  trzeba zmienić je w samej bazie (`ALTER USER`) albo zresetować wolumen (`down -v`, kasuje dane).
- **`ARCHIVE_HOST_DIR` vs `ARCHIVE_DIR` to dwa końce jednego bind mountu** (`${ARCHIVE_HOST_DIR}:/archive`),
  NIE sekrety. `ARCHIVE_HOST_DIR` (warstwa Compose, root `/.env`; dev default `../imap-archive` inline
  w `compose.yaml`) = katalog na **hoście**, gdzie fizycznie leżą `.eml` — to backupujesz, trzymaj
  POZA repo. `ARCHIVE_DIR` (warstwa Symfony; real env var z `compose.yaml` = `/archive` nadpisuje atrapę
  w `app/.env`) = katalog **w kontenerze**, który widzi `ArchiveStorage` (`%env(ARCHIVE_DIR)%`).
  Aplikacja nie zna ścieżki hosta — pisze do `/archive`; prod zmienia tylko `ARCHIVE_HOST_DIR`.
  Pliki w archiwum są **root-owe** (kontener działa jako root) — w dev OK, na prod uwzględnić przy
  backupie/UID.

## Gotchas — łatwo o tym zapomnieć

- `ext-imap` wypada z core PHP → używamy `webklex/php-imap` (v6). Uwaga: ciągnie zależności
  `illuminate/*` (kawałki Laravela) + `nesbot/carbon` — to normalne, nie pomyłka.
- **Webklex bez `ext-imap` NIE dekoduje nagłówków MIME — trzeba mu kazać `iconv`.** Domyślny dekoder
  (`header = 'utf-8'`) oddaje surowy tekst i zwiera obwód przed fallbackiem, więc temat/nadawca
  z polskimi znakami lądują jako `=?UTF-8?B?…?=`. Naprawa (bez wracania do `ext-imap`):
  `Message::fromString($raw, Config::make(['decoding' => ['options' => ['header' => 'iconv']]]))` —
  dekoduje spójnie temat i `personal` w adresach. Robi to `MessageFactory`. Osobno: webklex zostawia
  w `Address::$personal` cudzysłowy quoted-string — zdejmuje je `MessageFactory::cleanPersonal()`.
- **Webklex przy BRAKU nagłówka `Date` nie oddaje null — oddaje epokę.** `getDate()->first()` zwraca
  pusty string (atrybut istnieje, tylko jest pusty), więc warunek `=== null` nie zadziała, a
  `toDate()` na pustce daje Carbona `1970-01-01 00:00`. Mail bez daty dostawałby w indeksie fałszywą
  datę wysłania udającą prawdziwą (i sortowałby się jak najstarszy zamiast wypaść na koniec przez
  `NULLS LAST`). `MessageFactory::extractDate()` testuje więc PUSTKĘ WARTOŚCI, nie `null`.
  Wykrył to `MessageFactoryTest` — bez niego błąd byłby niewidoczny, bo `.eml` na dysku jest poprawny.
- **Read-only w webklex jest pozorne: `leaveUnread()` NIE robi PEEK, tylko set-then-unset.** Body leci
  przez `BODY[TEXT]` (ustawia `\Seen`), a flaga zdejmowana jest dopiero PO fakcie osobnym `STORE` —
  netto unseen zostaje unseen, ale to nie jest atomowy read-only. Ping był czysty głównie dzięki
  `setFetchBody(false)`, nie `leaveUnread()`. Dlatego treść pobieramy własnym
  `UID FETCH <uid> BODY.PEEK[]` — `BODY.PEEK[]` nie dotyka `\Seen` w ogóle. Webklex zwraca PEEK pod
  kluczem `BODY[]`, a przy JEDNYM itemie matcher go nie dopasuje („single id was not found") — wołać
  z wieloma: `['UID', 'BODY.PEEK[]']` i czytać `validatedData()[$uid]['BODY[]']`.
- **Pusty `query()` = `UID SEARCH` bez parametrów → niektóre serwery odpowiadają `BAD ... Missing
  search parameters`** (potwierdzone na nazwa.pl). webklex NIE dokleja domyślnego `ALL`. Zawsze podać
  kryterium: `->whereAll()` (jak w `app:imap:ping`) albo konkretne `->whereSince()/->whereOn()` po
  dacie. Dotyczy też SEARCH po dacie w imporcie (etap 3).
- **`SINCE/BEFORE` filtrują po INTERNALDATE (data przyjęcia przez serwer), NIE po nagłówku `Date`;
  po `Date` filtruje `SENTSINCE/SENTBEFORE`** (RFC 3501). Import roku (etap 3.3) selekcjonuje po
  **INTERNALDATE** (`->whereSince()/->whereBefore()`) i **nie odrzuca nic po fetchu** — zawężenie
  `SEARCH` do roku + drop po `Date` GUBI maile na granicy roku (INTERNALDATE i `Date` w różnych
  latach → mail wypada z obu roczników). Nagłówek `Date` ląduje w `Message.sent_at`; rozjazd tylko
  raportujemy. Pełne „dlaczego" w README → „Ciekawostki techniczne".
- **Szyfrowanie IMAP wnioskujemy z portu** w `ImapConnectionFactory`: 143→`tls` (STARTTLS), wszystko
  inne (w tym 993)→`ssl`. Gdyby pojawił się serwer wymagający innej kombinacji — dołożyć jawne pole,
  nie kombinować z portem. (Walidacja cert: flaga `IMAP_VALIDATE_CERT`, opisana w „Konfiguracja i sekrety".)
- **Postgres przy `ORDER BY … DESC` stawia NULL-e NA POCZĄTKU** (domyślnie `NULLS FIRST`), a
  `Message.sent_at` jest nullable — bez zabezpieczenia maile z nieparsowalnym `Date` przykryłyby
  najnowsze na szczycie listy. DQL nie zna `NULLS LAST`, więc `searchPage()` sortuje po sztucznej
  kolumnie `CASE WHEN m.date IS NULL THEN 1 ELSE 0 END AS HIDDEN` (`HIDDEN` = liczy się w `ORDER BY`,
  nie trafia do wyniku). Drugi warunek: `id DESC` jako tie-break — bez niego paginacja offsetowa
  gubi i dubluje rekordy między stronami przy równych datach.
- **DQL nie ma `ILIKE`, a postgresowy `LIKE` rozróżnia wielkość liter** → filtr listy porównuje
  przez `LOWER()` po obu stronach (fraza przez `mb_strtolower()`, żeby złapać polskie znaki).
  Do tego `ESCAPE '!'` i eskejpowanie `%`/`_` we frazie — inaczej samo „%" wpisane w wyszukiwarkę
  wybiera całe archiwum.
- Typ Doctrine `encrypted_string` rejestrujemy w `Kernel::boot()`, **nie** w `doctrine.yaml`
  (`dbal.types`). Powód: musimy wstrzyknąć w instancję typu serwis `CredentialEncryptor`, a
  rejestracja przez config nadpisuje (`overrideType`) instancję przy budowie połączenia i
  zgubiłaby encryptor. Nie przenosić tego z powrotem do configu „dla porządku".
- Worker mode (FrankenPHP) trzyma usługi w pamięci między żądaniami → nie przechowuj
  stanu żądania w usługach; stanowe usługi implementują `ResetInterface`, najlepiej trzymaj
  je bezstanowymi. UWAGA: worker **nie jest jeszcze włączony** — `FRANKENPHP_CONFIG` jest puste,
  dev działa w klasycznym `php_server` (zmiany kodu łapią się per-request, bez restartu).
  Worker wchodzi w **etapie 4** — czyli PO etapie 5, patrz kolejność w planie —
  (`FRANKENPHP_CONFIG=worker ./public/index.php`); dopiero wtedy
  zmiana klasy PHP wymaga restartu kontenera, by worker wczytał nowy kod. Powyższe zasady
  bezstanowości piszemy już teraz, żeby kod był gotowy na worker.
- `EntityManager` w długo żyjącym workerze: pamiętać o `clear()` i obsłudze zamkniętego EM.
- `docker compose down -v` KASUJE nazwane wolumeny — w tym bazę. Bez `-v` wolumeny zostają.
- Surowe archiwum `.eml` (źródło prawdy) idzie na **bind mount do katalogu hosta POZA repo**
  (`ARCHIVE_HOST_DIR`), nie na nazwany wolumen — nazwany wolumen ginie przy `down -v`, a katalog
  wewnątrz repo skasowałby `git clean -fdx`. Szczegóły w planie etapu 3.2.
- `var/` (cache, logi, profiler, build Tailwinda) jedzie z bind-mountu `./app:/app` na ext4 WSL —
  szybki filesystem, bez osobnego wolumenu. Build Tailwinda siedzi w `./app/var/tailwind/` i jest trwały;
  kasuje go tylko ręczny reset `var/` → wtedy `tailwind:build -m` ponownie.
- `tailwind:build`: nieudane pobranie binarki zostawia **plik 0-bajtowy** i bundle go nie pobiera ponownie
  → build kończy się EXIT=0 bez `app.built.css`, strony lecą 500. Naprawa: `rm -rf var/tailwind/<wersja>`
  i build ponownie.
- Docker działa tylko w dystrybucji WSL `dev-edor-gw` (nie w Docker Desktop): polecenia przez
  `wsl -d dev-edor-gw -e bash -lc "cd ~/projects/imap-archiver && docker compose ..."`.
- Git z WSL działa po HTTPS przez **Windows Git Credential Manager**: w repo ustawione
  `credential.helper = /mnt/c/Program\ Files/Git/mingw64/bin/git-credential-manager.exe` (spacja
  uciekana backslashem — inaczej `sh -c` rozbija ścieżkę: `/mnt/c/Program: not found`). Dzięki temu
  `git push`/`fetch` robimy już z WSL, nie trzeba przełączać się na PowerShell.
- EasyAdmin 5.x: w menu **nie ma `MenuItem::linkToCrud()`** (było we wcześniejszych wersjach).
  Link do CRUD-a robimy przez `MenuItem::linkTo(FooCrudController::class, 'Etykieta', 'fa fa-...')`.
- **EasyAdmin po polsku = `framework.default_locale: pl`** (NIE tłumaczenie ręczne). EA ma własne
  `EasyAdminBundle.pl` — przy locale `pl` „Back to listing", badge Yes/No itd. lecą po polsku same.
  Z `en` panel jest angielski MIMO polskich etykiet pól (etykiety to nasze stringi, a chrome EA to
  jego domena tłumaczeń).
- **EA: `formatValue()` działa TYLKO na polu o JAWNYM typie.** `IntegerField::new('size')
  ->formatValue(…)` → „48,0 KB"; `Field::new('size')->formatValue(…)` → surowe `49189` — generyczny
  `Field` gubi callable, gdy EA promuje go na zgadnięty typ. Dwa dodatkowe haczyki: `TextField`
  **rzuca** na wartości nie-`string` (do liczb `IntegerField`/`NumberField`), a pole **wirtualne**
  + `formatValue` daje „Niedostępny" (EA nie odczyta właściwości → `null`) — wirtualne renderuj
  własnym szablonem czytającym `entity.instance`. Rozmiary formatuje `App\Util\ByteFormatter`
  (+ filtr Twiga `|bytes`), żeby PHP i szablony liczyły tak samo.
- **EA odwraca układ pól boolean na detalu — TRZEMA regułami naraz, nie jedną.** Nadpisuje
  `flex-direction:row-reverse` na `.field-group.field-boolean` **plus** geometrię `.field-label`
  i `.field-value`. Poprawienie samego `flex-direction` nie wystarcza — wyrównanie i tak się rozjeżdża.
  Naprawa: `public/css/easyadmin-overrides.css` przez `configureAssets()->addCssFile()` (ładuje się
  PO CSS EA → wygrywa kolejnością, bez `!important`), neutralizujący **wszystkie 9 właściwości**.
  Metoda przy każdej takiej poprawce: wyciągnąć reguły ze skompilowanego `app.*.css` EA, porównać
  wariant zepsuty z bazowym, sprawdzić pokrycie właściwość po właściwości — nie zgadywać po jednej.
  UWAGA: `addHtmlContentToHead('<style>…')` bywa tu zawodny (styl jest w HTML, nie trafia do CSSOM;
  przyczyny nie ustalono). Używać `addCssFile()`.
- **PHPUnit 13 + `failOnNotice="true"`: `createMock()` bez `expects()` wywala build.** Atrapa, która
  ma tylko oddać wartość (`willReturn`), musi być `createStub()` — inaczej leci notice „No expectations
  were configured…" i cały przebieg jest czerwony mimo przechodzących asercji.
- Testy funkcjonalne wypisują na stderr `[error] Uncaught PHP Exception … Access Denied` przy
  sprawdzaniu 403/404. To NIE jest błąd testu, tylko logger aplikacji — przypadki oczekujące
  403/404 zawsze tak hałasują.
- Stateless CSRF (config/packages/csrf.yaml): formularze renderują token jako literał
  `csrf-token` (JS go podmienia w przeglądarce). Walidacja przechodzi, gdy żądanie jest
  same-origin (nagłówek `Origin`/`Referer` zgodny z hostem) **i** w POST jest pole tokenu
  o wartości placeholdera. Test logowania curl-em wymaga więc:
  `-H "Origin: http://localhost" --data-urlencode "_csrf_token=csrf-token"`. Akcja delete
  w EasyAdmin używa zwykłego sesyjnego tokenu (nie stateless) + `_method=DELETE`.
