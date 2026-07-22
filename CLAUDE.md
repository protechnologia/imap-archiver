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
- [x] Etap 1 — model danych, logowanie, EasyAdmin (userzy, konta). Podetapy:
  - [x] Etap 1.1 — encja `User` (email, hasło hashowane, role), security provider, formularz
        logowania, `ROLE_ADMIN`/`ROLE_USER`, pierwszy admin (komenda `app:user:create`).
        ➜ działa: logowanie i wylogowanie.
  - [x] Etap 1.2 — EasyAdmin za `ROLE_ADMIN` + CRUD `User` (zakładanie, role, reset hasła).
        ➜ działa: admin zarządza użytkownikami z panelu (lista, dodawanie z hashowaniem
        hasła, edycja ról i reset hasła, usuwanie). Pulpit `/admin` przekierowuje na listę.
  - [x] Etap 1.3 — encja `MailAccount` (label, host, port, imapLogin, folder, authType jako
        enum `AuthType`) + many-to-many `User ↔ MailAccount` (właściciel: `MailAccount`,
        join table `mail_account_user` z `ON DELETE CASCADE`). ➜ działa: model kont w bazie,
        migracja przechodzi, `schema:validate` czysty. Poświadczenia (szyfrowane) — etap 1.4.
  - [x] Etap 1.4 — szyfrowanie poświadczeń at-rest. Pole `MailAccount.secret` (hasło lub
        refresh_token wg `authType`) szyfrowane przezroczyście custom typem Doctrine
        `encrypted_string` (libsodium `secretbox`, nonce per zapis, base64). Serwis
        `CredentialEncryptor`, klucz `MAIL_CRYPTO_KEY`; typ rejestrowany i zasilany
        encryptorem w `Kernel::boot()`. ➜ działa: w bazie leży szyfrogram, ORM zwraca
        wartość jawną (round-trip zweryfikowany), `schema:validate` czysty.
  - [x] Etap 1.5 — CRUD `MailAccount` w EasyAdmin (`MailAccountCrudController`) + przypisywanie
        userów do kont (`AssociationField`, M2M). Pole `secret` jako `PasswordType` `mapped=false`,
        `onlyOnForms()`; listener `POST_SUBMIT` przepisuje plaintext na encję, a puste pole przy
        edycji = bez zmiany (nie nadpisuje szyfrogramu). Tylko `AuthType::Password` (XOAUTH2 czeka
        na decyzję o dostawcy — Etap 2). `User::__toString()` (email) dla list wyboru relacji.
        ➜ działa: admin zakłada konto IMAP i przydziela dostęp; w bazie szyfrogram, pusty `secret`
        nie kasuje hasła, przypisania w `mail_account_user` (zweryfikowane).
        (`Message`/`Attachment` powstają realnie przy imporcie — Etap 3.)
- [x] Etap 2 — spike połączenia IMAP (CLI). Decyzja o dostawcy: **własny IMAP + hasło** na start
      (XOAUTH2 odłożone na osobny podetap). Biblioteka `webklex/php-imap` (v6). Serwis
      `ImapConnectionFactory` buduje POŁĄCZONEGO klienta z `MailAccount` (host/port/login + `secret`
      deszyfrowany przez ORM); szyfrowanie z portu (993→ssl, 143→tls); `validate_cert` z
      `IMAP_VALIDATE_CERT`. Komenda `app:imap:ping --account=<id> [--limit=N]` listuje foldery,
      EXAMINE skonfigurowanego folderu i nagłówki najnowszych wiadomości. READ-ONLY (`leaveUnread()`,
      bez fetch body, bez zmiany flag, nic nie kasuje). ➜ działa: ścieżki błędów (brak/zły `--account`,
      nieistniejące ID, nieudane połączenie) raportowane czytelnie; `lint:container` czysty. Happy-path
      do potwierdzenia na realnym serwerze IMAP. Żadnego zapisu `.eml`/`Message` — to Etap 3.
- [x] Etap 3 — import synchroniczny, mały zakres (`.eml` + DB, idempotentnie).
      Komenda `app:archive:import --account=<id> --year=<rok>`: pobiera maile z roku, zapisuje
      surowy `.eml` (źródło prawdy) + `sha256`, indeksuje `Message` w DB, oznacza `verified`.
      Synchronicznie (Messenger dopiero etap 4), read-only wobec skrzynki. ➜ komplet 3.0–3.4;
      podgląd admina (3.4) domyka etap. Async/skala/progress = etap 4.
  - [x] Etap 3.0 — spike pobrania bajtowo-wiernego `.eml` (komenda `app:imap:spike-raw`). webklex
        `getRawBody()` zwraca tylko *body* (`structure->raw`), nie pełny RFC822. Porównano (A)
        `header->raw . "\r\n\r\n" . getRawBody()` vs (B) surowy `UID FETCH <uid> BODY.PEEK[]`.
        ➜ **DECYZJA: bierzemy B.** Na koncie 67 (2 maile, w tym jeden z 2 załącznikami) A i B wyszły
        **bajtowo identyczne** (ten sam `sha256`, oba re-parsują się czysto przez `Message::fromString`,
        zgodny Message-ID/temat/#załączników) — co potwierdza, że nic się nie gubi. Mimo to docelowo
        B, bo: (1) to dosłownie źródło z serwera — równość A==B bywa przypadkowa, B nie ma jak się
        rozjechać przy nietypowych kodowaniach/końcach linii; (2) B (PEEK) nie rusza flag, a ścieżka
        A pobiera body przez `BODY[TEXT]` → ustawia `\Seen` (zob. gotcha o `leaveUnread`). **`sha256`
        liczymy nad bajtami z B.** W 3.3 po pobraniu B raz wyprowadzamy WSZYSTKIE pola `Message`/
        `Attachment` przez `Message::fromString($raw)` na tych samych zapisanych bajtach — DB
        odzwierciedla dokładnie to, co leży w `.eml`.
  - [x] Etap 3.1 — model danych: `Message` + `Attachment` + migracja. `Message`: `account`
        (ManyToOne→`MailAccount`, FK `account_id`), `folder`, `messageId`, `subject`, `fromName`,
        `fromEmail`, `date` (kolumna `sent_at` — unikamy słowa kluczowego `date`), `size`, `sha256`,
        `hasAttachments`, `verified`, `body`, `imapUid`, `archivePath` (ścieżka względna do pliku).
        `Attachment`: `message` (ManyToOne→`Message`, FK `ON DELETE CASCADE`), `filename`, `mimeType`,
        `size`, opcjonalnie `sha256` (metadane w DB; bajty zostają w `.eml`, nie duplikujemy na dysk).
        ➜ **Idempotencja: `UNIQUE(account_id, sha256)`** — ODSTĘPSTWO od pierwotnego `(account_id,
        message_id, sha256)`: `message_id` bywa NULL (w Postgresie `NULL ≠ NULL` → nie nadawałby się
        na klucz), a `sha256` surowego `.eml` jest zawsze obecny i to ON jest tożsamością treści. Te
        same bajty = ten sam wpis; `message_id` zostaje nullable + zindeksowany do cross-referencji.
        FK `message→mail_account` BEZ cascade (guard: nie skasujesz konta z zarchiwizowanymi mailami).
        Indeksy: `(account_id, sent_at)` pod listę, `message_id`. ➜ działa: migracja przeszła,
        `schema:validate` czysty (mapping + sync). (UWAGA: to NIE jest M2M — Message↔Account i
        Attachment↔Message to ManyToOne; stroną właścicielską jest strona „wiele", trzymająca FK.)
  - [x] Etap 3.2 — magazyn archiwum: bind mount POZA repo + serwis `ArchiveStorage`. Surowe `.eml`
        = źródło prawdy → bind mount do katalogu hosta, NIE nazwany wolumen (ginie przy `down -v`)
        i NIE wewnątrz repo (`git clean -fdx` kasuje też ignorowane). Katalog-sibling na ext4 WSL,
        wskazany zmienną `ARCHIVE_HOST_DIR` (root `/.env`; dev default `../imap-archive`) → mount
        `${ARCHIVE_HOST_DIR}:/archive` w `compose.yaml`, `ARCHIVE_DIR=/archive` w kontenerze.
        `ArchiveStorage::store()`: układ `<accountId>/<rok>/<mm>/<sha256>.eml`, zapis ATOMOWY przez
        `Filesystem::dumpFile()` (temp+rename), `sha256` nad dokładnie tym, co zapisano, zwrot
        `ArchivedFile(relativePath, sha256, size)`; idempotentnie po treści (jest plik o tym sha256 →
        nie nadpisuje). `path()`/`read()` (z guardem path-traversal) do weryfikacji w 3.3. Nazwa
        pliku = sha256 → naturalna deduplikacja i weryfikowalność. ➜ działa: mount widoczny (`/archive`
        root-owy), `lint:container` czysty, smoke (zapis→sha256→read round-trip→idempotencja)
        potwierdzony, plik fizycznie na hoście, host-owy `sha256sum` = nazwa pliku. Backup
        `ARCHIVE_HOST_DIR` = obowiązek operacyjny. Pliki root-owe (kontener=root) — na prod uwzględnić.
  - [x] Etap 3.3 — pipeline importu: `ImapReader` (IMAP) + `ImportManager` (polityka) + komenda.
        Rozbite na podetapy: 3.3a (komenda + SEARCH roku), 3.3b (pobranie `.eml` + zapis do archiwum),
        3.3c (idempotencja po DB + indeks `Message`/`Attachment` + weryfikacja). SEARCH po INTERNALDATE
        (`->whereSince()` + górna granica; NIE pusty query — gotcha o pustym SEARCH), treść przez
        `BODY.PEEK[]` (read-only, bez `\Seen`). Per mail w `ImportManager`: surowe źródło → `sha256` →
        idempotencja (`MessageRepository::existsForContent(accountId, sha256)` po `UNIQUE(account_id,
        sha256)` — NIE po `messageId`, bywa NULL; jest → `skipped`) → `ArchiveStorage::store()` →
        `MessageFactory::fromRaw()` wyprowadza `Message`+`Attachment` z TYCH SAMYCH bajtów przez
        `Message::fromString()` → persist+flush. Weryfikacja: odczyt pliku z dysku, przelicz `sha256`,
        zgodne → `verified=true` (realizuje warunek bezpiecznego kasowania z etapu 6: jest w DB + jest
        plik + checksum się zgadza). Komenda `app:archive:import --account=<id> --year=<rok> [--dry-run]`
        z podsumowaniem (kandydaci / nowe / duplikaty / zweryfikowane / błędy). ➜ **zweryfikowane na
        koncie #67**: import 3/3/0 (nowe/zweryfikowane/błędy), idempotencja (ponowny przebieg 0/3
        nowe/duplikaty), tematy i nazwy nadawcy zdekodowane (patrz gotcha o dekoderze webklex),
        `sha256sum` plików = nazwa, `lint:container`/`schema:validate` czyste. Poza zakresem etapu 3:
        async/progress (etap 4), podgląd DLA UŻYTKOWNIKÓW (5), JAKIEKOLWIEK kasowanie z serwera (6) —
        tu tylko `verified`, zero `\Deleted`.
  - [x] Etap 3.4 — diagnostyczny podgląd `Message` w EasyAdmin (`MessageCrudController`),
        **read-only**. Po imporcie (3.3) admin ogląda zaimportowane wiadomości: temat, nadawca
        (`fromName`/`fromEmail`), data, rozmiar (human-readable), `verified`, `hasAttachments` (na liście),
        konto; w detalu dodatkowo `messageId`/`sha256`/`imapUid`/`archivePath`/folder + lista `Attachment`
        (metadane: nazwa, MIME, rozmiar) renderowana WŁASNYM szablonem `admin/message_attachments.html.twig`
        (bez osobnego CRUD-a — Attachment to czyste metadane, nie ma po co linkować). Tylko `index` +
        `detail`: `configureActions()` wyłącza `NEW/EDIT/DELETE/BATCH_DELETE` (`Message` to indeks — edycja
        bez sensu; kasowanie zarchiwizowanej poczty to etap 6 z audytem), wejście na te trasy → 403. Menu:
        sekcja „Archiwum" → „Wiadomości". `size` renderowane generycznym `Field` (NIE `TextField` — ten
        wymusza string i rzuca na wartości `int`) + `formatValue`. To NIE jest podgląd dla użytkowników
        (trójpanelowy Twig/UX + Voter + sandbox iframe) — ten zostaje Etapem 5; 3.4 to tani „wgląd admina"
        w efekt importu. Treść maila (`body`) renderujemy dopiero w etapie 5 (tu bez body/iframe). ➜ działa:
        login dev-admina, lista 3 maili z konta #67 (tematy/nazwy zdekodowane), detal #3 z tabelką 2
        załączników, #2 „Brak załączników", `new`/`edit` → 403, `lint:container`/`lint:twig` czyste.
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
docker compose exec php php bin/console dbal:run-sql "SELECT 1"   # smoke test DB

# użytkownicy (od etapu 1)
docker compose exec php php bin/console app:user:create <email> [hasło] [--admin]
# konto testowe (dev) założone w etapie 1.1: admin@example.com / Tajne123! (ROLE_ADMIN)

# front: build CSS Tailwind (od etapu 1; v4, bez Node)
docker compose exec php php bin/console tailwind:build [-m] [--watch]

# spike połączenia IMAP (od etapu 2) — read-only, listuje foldery + nagłówki najnowszych
docker compose exec php php bin/console app:imap:ping --account=<id> [--limit=N]

# import (od etapu 3)
docker compose exec php php bin/console app:archive:import --account=<id> --year=<rok>

# konsument kolejki (od etapu 4)
docker compose exec php php bin/console messenger:consume async -vv
```

Aplikacja w dev: `http://localhost:8180` (FrankenPHP na `SERVER_NAME=":80"`, port 8180→80).

Projekt mieszka w **natywnym FS WSL**: `~/projects/imap-archiver` (`/root/projects/imap-archiver`
w dystrybucji `dev-edor-gw`), **nie** na `/mnt/c`. Edycja przez VS Code Remote-WSL; dostęp z
Eksploratora Windows pod `\\wsl.localhost\dev-edor-gw\root\projects\imap-archiver`.

## Otwarte decyzje

- ~~**Dostawca poczty:**~~ ROZSTRZYGNIĘTE (etap 2): **własny serwer IMAP z hasłem** na start
  (`AuthType::Password`). Gmail / M365 (OAuth2/XOAUTH2) odłożone na osobny podetap — `AuthType::Xoauth2`
  już istnieje w enumie, `ImapConnectionFactory` świadomie odrzuca je wyjątkiem do czasu implementacji.
- **Baza:** Postgres (zdecydowane). MySQL jako znana alternatywa, gdyby zaszła potrzeba.

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
- **Webklex NIE dekoduje nagłówków MIME (encoded-words) bez `ext-imap` — trzeba dekoderowi kazać
  `iconv`.** Domyślny dekoder nagłówków (`decoding.options.header = 'utf-8'`) woła `mimeHeaderDecode()`,
  który BEZ `ext-imap` oddaje surowy tekst (nie ma `imap_mime_header_decode`); ta niepusta surówka
  zwiera obwód, zanim kod dojdzie do fallbacku `iconv_mime_decode`. Skutek: temat/nazwa nadawcy z
  polskimi znakami lądują jako `=?UTF-8?B?…?=`. Naprawa (świadomie BEZ wracania do `ext-imap`):
  `Message::fromString($raw, Config::make(['decoding' => ['options' => ['header' => 'iconv']]]))` —
  tryb `iconv` woła wprost `iconv_mime_decode()` (`ext-iconv` jest w buildzie) i dekoduje spójnie temat
  oraz `personal` w adresach (dzielą tę ścieżkę). Robi to `MessageFactory` (import, etap 3.3c). Osobno:
  webklex zostawia w `Address::$personal` zewnętrzne cudzysłowy quoted-string (`"Jan Kowalski"`) —
  `MessageFactory::cleanPersonal()` zdejmuje jedną dopasowaną parę.
- **IMAP read-only naprawdę read-only:** webklex domyślnie przy pobraniu wiadomości ustawia flagę
  `\Seen`. Żeby NIE mutować skrzynki (krytyczne dla archiwizatora), w query woła się `->leaveUnread()`
  (+ `->setFetchBody(false)` gdy potrzebne tylko nagłówki). `app:imap:ping` tak robi; pamiętać o tym
  też przy imporcie (etap 3).
- **UWAGA: `leaveUnread()` NIE pobiera przez PEEK — robi set-then-unset.** Body w Query leci przez
  `BODY[TEXT]` (ustawia `\Seen`), a `Message::peek()` zdejmuje flagę dopiero PO fakcie (`unsetFlag("Seen")`,
  dodatkowy `STORE`). Netto unseen zostaje unseen, ale to nie jest atomowy read-only. W etapie 2 ping
  był czysty głównie dlatego, że NIE pobierał body (`setFetchBody(false)`), a nie dzięki `leaveUnread()`.
  Dlatego import (etap 3.3) pobiera surowe źródło przez własny `UID FETCH <uid> BODY.PEEK[]` (decyzja
  spike’u 3.0) — `BODY.PEEK[]` nie dotyka `\Seen` w ogóle. Webklex zwraca PEEK w odpowiedzi jako `BODY[]`;
  przy jednym itemie matcher tego nie dopasuje (rzuca „single id was not found") — wołać z wieloma itemami
  `['UID', 'BODY.PEEK[]']` i czytać `validatedData()[$uid]['BODY[]']`.
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
- Typ Doctrine `encrypted_string` rejestrujemy w `Kernel::boot()`, **nie** w `doctrine.yaml`
  (`dbal.types`). Powód: musimy wstrzyknąć w instancję typu serwis `CredentialEncryptor`, a
  rejestracja przez config nadpisuje (`overrideType`) instancję przy budowie połączenia i
  zgubiłaby encryptor. Nie przenosić tego z powrotem do configu „dla porządku".
- Worker mode (FrankenPHP) trzyma usługi w pamięci między żądaniami → nie przechowuj
  stanu żądania w usługach; stanowe usługi implementują `ResetInterface`, najlepiej trzymaj
  je bezstanowymi. UWAGA: worker **nie jest jeszcze włączony** — `FRANKENPHP_CONFIG` jest puste,
  dev działa w klasycznym `php_server` (zmiany kodu łapią się per-request, bez restartu).
  Worker wchodzi w **etapie 4** (`FRANKENPHP_CONFIG=worker ./public/index.php`); dopiero wtedy
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
- **EA: `formatValue()` na polu numerycznym jest ZJADANY przez `IntegerConfigurator`.** Kolejność
  konfiguratorów: `CommonPostConfigurator` (stosuje `formatValue`, priorytet −9999) biegnie, ale pole
  z kolumny `int` EA zgaduje jako `IntegerField`, którego konfigurator BEZWARUNKOWO nadpisuje
  `formattedValue` surową liczbą. Skutek: `Field/IntegerField::new('size')->formatValue(fn…KB)` i tak
  pokazuje `49189`. Pole WIRTUALNE (`TextField::new('sizeHuman')`) też nie ratuje — EA traktuje
  nie-czytelną właściwość jako null → „Niedostępny". **Naprawa: własny szablon** przez
  `setTemplatePath()` i formatowanie z `field.value` w Twigu (makro `admin/_bytes.html.twig`). Tak robi
  `MessageCrudController` dla rozmiaru (etap 3.4). Dla `TextField` osobny haczyk: jego konfigurator
  RZUCA na wartości nie-`string` (np. `int`) — patrz sam błąd „can't be converted into a string".
- Stateless CSRF (config/packages/csrf.yaml): formularze renderują token jako literał
  `csrf-token` (JS go podmienia w przeglądarce). Walidacja przechodzi, gdy żądanie jest
  same-origin (nagłówek `Origin`/`Referer` zgodny z hostem) **i** w POST jest pole tokenu
  o wartości placeholdera. Test logowania curl-em wymaga więc:
  `-H "Origin: http://localhost" --data-urlencode "_csrf_token=csrf-token"`. Akcja delete
  w EasyAdmin używa zwykłego sesyjnego tokenu (nie stateless) + `_method=DELETE`.
