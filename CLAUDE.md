# imap-archiver

Aplikacja do archiwizacji poczty z serwera IMAP. Pobiera wiadomości (np. z konkretnego
roku), zapisuje surowe `.eml` jako źródło prawdy, indeksuje je w bazie do podglądu i
wyszukiwania, i opcjonalnie kasuje z serwera po świadomym potwierdzeniu admina.
Wielu użytkowników z dostępem do podglądu; jeden admin robi import i zarządza kontami.

## Konwencje

- Komunikacja, UI i treści po polsku; kod, identyfikatory i nazwy plików po angielsku.
- `declare(strict_types=1)` w każdym pliku PHP. PSR-12.
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
- **Pipeline importu:** IMAP `SEARCH` po dacie → worker parsuje → zapis `.eml` + `sha256`
  → zapis `Message` do DB → oznaczenie `verified`. Idempotentnie po `Message-ID` + checksum
  (ponowny import nie duplikuje).
- **Import zawsze asynchronicznie** (Messenger), nigdy w żądaniu HTTP.
- **Bezpieczne usuwanie (KRYTYCZNE).** Kasować wolno TYLKO wiadomości `verified`
  (są w DB + jest plik `.eml` + checksum się zgadza). Przepływ: import → weryfikacja →
  podsumowanie dla admina → świadome potwierdzenie → `\Deleted` + `EXPUNGE`
  (bezpieczniej: najpierw przenieść do `Archived/`). Nigdy automatycznie. Log audytowy usunięć.
- **Dostęp:** `ROLE_ADMIN` / `ROLE_USER`, mapowanie many-to-many `User ↔ MailAccount`,
  Voter na poziomie podglądu wiadomości.

## Model danych

- `User`, `MailAccount`, `Message`, `Attachment`.
- `Message`: account, folder, messageId, subject, fromName, fromEmail, date, size,
  sha256, hasAttachments, verified, body (text/html lub render z `.eml`).
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
- Szew na full-text: filtr listy idzie przez `MessageRepository::findForList()`
  (na start `LIKE`). Na etapie 7 podmieniamy wnętrze tej metody na Meilisearch bez
  ruszania komponentu i szablonu.

## Plan inkrementalny (status)

Każdy etap kończy się czymś działającym. Niebezpieczne usuwanie (etap 6) podłączamy
dopiero, gdy import, archiwum i weryfikacja są pewne.

- [x] Etap 0 — szkielet Symfony + Docker (FrankenPHP + Postgres). Pliki przygotowane:
      `compose.yaml`, `Dockerfile`, `frankenphp/Caddyfile`.
- [ ] Etap 1 — model danych, logowanie, EasyAdmin (userzy, konta). Podetapy:
  - [x] Etap 1.1 — encja `User` (email, hasło hashowane, role), security provider, formularz
        logowania, `ROLE_ADMIN`/`ROLE_USER`, pierwszy admin (komenda `app:user:create`).
        ➜ działa: logowanie i wylogowanie.
  - [x] Etap 1.2 — EasyAdmin za `ROLE_ADMIN` + CRUD `User` (zakładanie, role, reset hasła).
        ➜ działa: admin zarządza użytkownikami z panelu (lista, dodawanie z hashowaniem
        hasła, edycja ról i reset hasła, usuwanie). Pulpit `/admin` przekierowuje na listę.
  - [ ] Etap 1.3 — encja `MailAccount` (host, port, login, folder, typ auth) + many-to-many
        `User ↔ MailAccount`. ➜ działa: model kont w bazie, migracja przechodzi.
  - [ ] Etap 1.4 — szyfrowanie poświadczeń at-rest (hasło/sekret szyfrowane, NIGDY plaintext;
        pole typ auth: hasło vs OAuth2/XOAUTH2; klucz w sekretach).
        ➜ działa: hasło konta nie leży jawnie w bazie.
  - [ ] Etap 1.5 — CRUD `MailAccount` w EasyAdmin + przypisywanie userów do kont.
        ➜ działa: admin zakłada konto IMAP i przydziela dostęp.
        (`Message`/`Attachment` powstają realnie przy imporcie — Etap 3.)
- [ ] Etap 2 — spike połączenia IMAP (CLI). BLOKADA: wymaga decyzji o dostawcy poczty.
- [ ] Etap 3 — import synchroniczny, mały zakres (`.eml` + DB, idempotentnie).
- [ ] Etap 4 — async (Messenger) + skala + progress.
- [ ] Etap 5 — podgląd dla użytkowników (Twig/UX trójpanelowy, Voter, sandbox iframe).
- [ ] Etap 6 — bezpieczne usuwanie (weryfikacja + potwierdzenie + EXPUNGE + audyt).
- [ ] Etap 7 — full-text search (Meilisearch).

## Polecenia (dev)

```bash
docker compose up -d --build
docker compose exec php php bin/console <komenda>
docker compose exec php composer <komenda>
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose exec php php bin/console doctrine:query:sql "SELECT 1"   # smoke test DB

# użytkownicy (od etapu 1)
docker compose exec php php bin/console app:user:create <email> [hasło] [--admin]
# konto testowe (dev) założone w etapie 1.1: admin@example.com / Tajne123! (ROLE_ADMIN)

# front: build CSS Tailwind (od etapu 1; v4, bez Node)
docker compose exec php php bin/console tailwind:build [-m] [--watch]

# import (od etapu 3)
docker compose exec php php bin/console app:archive:import --account=<id> --year=<rok>

# konsument kolejki (od etapu 4)
docker compose exec php php bin/console messenger:consume async -vv
```

Aplikacja w dev: `http://localhost:8180` (FrankenPHP na `SERVER_NAME=":80"`, port 8180→80).

## Otwarte decyzje

- **Dostawca poczty:** własny serwer IMAP z hasłem vs Gmail / M365 (OAuth2/XOAUTH2).
  Realnie zmienia warstwę uwierzytelniania — blokuje etap 2.
- **Baza:** Postgres (zdecydowane). MySQL jako znana alternatywa, gdyby zaszła potrzeba.

## Gotchas — łatwo o tym zapomnieć

- `ext-imap` wypada z core PHP → używamy `webklex/php-imap`.
- Worker mode (FrankenPHP) trzyma usługi w pamięci między żądaniami → nie przechowuj
  stanu żądania w usługach; stanowe usługi implementują `ResetInterface`, najlepiej trzymaj
  je bezstanowymi.
- `EntityManager` w długo żyjącym workerze: pamiętać o `clear()` i obsłudze zamkniętego EM.
- `docker compose down -v` KASUJE nazwane wolumeny — w tym bazę. Bez `-v` wolumeny zostają.
- Surowe archiwum `.eml` będzie potrzebowało własnego, osobnego wolumenu — dodać przy etapie 3.
- `APP_SECRET` trzymamy w sekrecie i nie rotujemy bez powodu (unieważnia podpisy Live Components).
- `var/` (cache, logi, profiler) jest na **nazwanym wolumenie** `app_var` (compose.yaml), nie na
  bind-mouncie Windows — inaczej profiler timeoutuje (`max_execution_time` przy `Filesystem::dumpFile`).
  Skutek: reset tego wolumenu (lub `down -v`) kasuje też build Tailwinda → po `up` zrób `tailwind:build -m`.
- `tailwind:build`: nieudane pobranie binarki zostawia **plik 0-bajtowy** i bundle go nie pobiera ponownie
  → build kończy się EXIT=0 bez `app.built.css`, strony lecą 500. Naprawa: `rm -rf var/tailwind/<wersja>`
  i build ponownie.
- Docker działa tylko w dystrybucji WSL `dev-edor-gw` (nie w Docker Desktop): polecenia przez
  `wsl -d dev-edor-gw -e bash -lc "cd /mnt/c/... && docker compose ..."`.
- EasyAdmin 5.x: w menu **nie ma `MenuItem::linkToCrud()`** (było we wcześniejszych wersjach).
  Link do CRUD-a robimy przez `MenuItem::linkTo(FooCrudController::class, 'Etykieta', 'fa fa-...')`.
- Stateless CSRF (config/packages/csrf.yaml): formularze renderują token jako literał
  `csrf-token` (JS go podmienia w przeglądarce). Walidacja przechodzi, gdy żądanie jest
  same-origin (nagłówek `Origin`/`Referer` zgodny z hostem) **i** w POST jest pole tokenu
  o wartości placeholdera. Test logowania curl-em wymaga więc:
  `-H "Origin: http://localhost" --data-urlencode "_csrf_token=csrf-token"`. Akcja delete
  w EasyAdmin używa zwykłego sesyjnego tokenu (nie stateless) + `_method=DELETE`.
