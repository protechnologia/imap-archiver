# imap-archiver

Aplikacja do archiwizacji poczty z serwera IMAP: pobiera wiadomości (np. z konkretnego
roku), zapisuje surowe `.eml` jako źródło prawdy, indeksuje je w bazie do podglądu
i wyszukiwania, i opcjonalnie kasuje z serwera po świadomym potwierdzeniu admina.

Plan etapów, architektura i zasady projektu: zobacz [`CLAUDE.md`](./CLAUDE.md).

## Wymagania

- Docker
- Docker Compose

## Stack

- Symfony 7.4 (LTS)
- FrankenPHP
- PostgreSQL 16
- Symfony UX (Turbo + Stimulus + Live Components)
- Tailwind (AssetMapper, bez Node)
- EasyAdmin
- Docker

## Pierwsze uruchomienie (dev)

> To ścieżka **dev** — działa bez żadnej konfiguracji. Na produkcji najpierw ustaw sekrety
> (zobacz [Konfiguracja i sekrety](#konfiguracja-i-sekrety) i [Produkcja — krok po kroku](#produkcja--krok-po-kroku)),
> dopiero potem te kroki.

1. **Zbuduj i wstań:**

   ```bash
   docker compose up -d --build
   ```

   Postgres ma healthcheck, więc `php` ruszy dopiero, gdy baza będzie gotowa.

2. **Migracje** (utworzą tabele, m.in. `user`):

   ```bash
   docker compose exec php php bin/console doctrine:migrations:migrate
   ```

3. **Zbuduj CSS Tailwind** (bez tego strony lecą 500):

   ```bash
   docker compose exec php php bin/console tailwind:build -m
   ```

4. **Załóż pierwszego admina:**

   ```bash
   docker compose exec php php bin/console app:user:create admin@example.com 'Tajne123!' --admin
   ```

5. **Otwórz** `http://localhost:8180`. Bez sesji przekieruje na `/login` — zaloguj się
   kontem z kroku 4. Pasek debugowania (Web Profiler) widać na dole strony w trybie `dev`.

> Pracujesz w środowisku, gdzie Docker działa tylko w dystrybucji WSL `dev-edor-gw`?
> Poprzedzaj polecenia: `wsl -d dev-edor-gw -e bash -lc "cd /mnt/c/... && docker compose ..."`.

## Konfiguracja i sekrety

Sekrety dzielą się na dwie warstwy, bo czytają je dwa różne procesy:

- **Sekrety aplikacji** (np. `APP_SECRET`) → `app/.env.local` (gitignorowany), czyta Symfony.
- **Sekrety Compose** (`DB_PASSWORD`) → root-owy `/.env` (gitignorowany), czyta wyłącznie
  Docker Compose przy podstawianiu `${...}` (≠ `app/.env`).

**Dev działa bez dodatkowej konfiguracji** — `compose.yaml` ma wbudowany default
`${DB_PASSWORD:-ChangeMe}`, a `APP_SECRET` nie jest krytyczny lokalnie. Nic nie zakładasz.

| Ustawienie | Gdzie ustawić | Opis |
| --- | --- | --- |
| `APP_SECRET` | `app/.env.local` (prod) / `app/.env.dev` (dev) | Sekret Symfony — podpisuje m.in. `LiveProp` i ciasteczka sesji. Nie rotować bez powodu (unieważnia podpisy). |
| `MAIL_CRYPTO_KEY` | `app/.env.local` (prod) / `app/.env.dev` (dev) | Klucz 32 B hex szyfrujący `MailAccount.secret` at-rest (libsodium). Rotacja bez re-encryptu = utrata poświadczeń IMAP. |
| `IMAP_VALIDATE_CERT` | `app/.env` (default `1`), override w `app/.env.local` | Walidacja certyfikatu TLS serwera IMAP. `0` tylko dla self-signed cert w dev. |
| `DB_PASSWORD` | root `/.env` (prod) / default `ChangeMe` w `compose.yaml` (dev) | Hasło Postgresa — czytane przez kontener bazy i wstrzykiwane do `DATABASE_URL` aplikacji. Warstwa Compose. |
| `DATABASE_URL` | `compose.yaml` (real env var; nadpisuje `app/.env`) | DSN połączenia z bazą. Wartość w `app/.env` to tylko atrapa dla trybu non-Docker. |
| `ARCHIVE_HOST_DIR` | root `/.env` (prod) / default `../imap-archive` w `compose.yaml` (dev) | Katalog **na hoście**, gdzie fizycznie leżą surowe `.eml` (źródło prawdy) — to backupujesz. Lewa strona bind mountu. Warstwa Compose. Trzymaj POZA repo. |
| `ARCHIVE_DIR` | `compose.yaml` (real env var; nadpisuje `app/.env`) | Katalog archiwum **w kontenerze** (`/archive`), który widzi aplikacja. Prawa strona bind mountu. Wartość w `app/.env` to tylko atrapa dla trybu non-Docker. |
| `APP_ENV` | `app/.env` (`dev`) / `app/.env.local` (`prod`) | Środowisko Symfony (`dev`/`prod`). |

## Produkcja — krok po kroku

Te kroki wykonaj **przed** `docker compose up` — sekrety muszą istnieć, zanim wstaną kontenery.

1. **Hasło bazy (warstwa Compose).** Skopiuj szablon i wpisz realne hasło:

   ```bash
   cp .env.dist .env
   # edytuj /.env i ustaw np. DB_PASSWORD=<silne-hasło>
   ```

   Ten root-owy `/.env` jest gitignorowany. Compose użyje go zarówno dla `POSTGRES_PASSWORD`
   (kontener bazy), jak i dla hasła w `DATABASE_URL` (aplikacja) — jedno źródło, obie strony spójne.

   > UWAGA: `POSTGRES_PASSWORD` działa tylko przy **pierwszej** inicjalizacji wolumenu
   > `database_data`. Jeśli baza już istnieje, zmiana `DB_PASSWORD` jej nie przestawi —
   > trzeba `ALTER USER` albo zresetować wolumen (`down -v`, kasuje dane).

2. **Sekrety aplikacji (warstwa Symfony).** Załóż `app/.env.local` i ustaw `APP_SECRET`
   oraz `MAIL_CRYPTO_KEY` (klucz szyfrowania poświadczeń IMAP):

   ```bash
   # każdy sekret to osobne 32 bajty hex
   openssl rand -hex 32   # dla APP_SECRET
   openssl rand -hex 32   # dla MAIL_CRYPTO_KEY
   ```

   ```dotenv
   # app/.env.local
   APP_ENV=prod
   APP_SECRET=<wynik-openssl-1>
   MAIL_CRYPTO_KEY=<wynik-openssl-2>
   ```

   Ten plik jest gitignorowany. **Nie rotuj `APP_SECRET`** bez powodu (unieważnia podpisy
   Live Components) ani **`MAIL_CRYPTO_KEY`** (bez re-encryptu istniejące poświadczenia IMAP
   stają się nieodczytywalne).

Po ustawieniu obu warstw wstań i zmigruj jak w sekcji „Pierwsze uruchomienie (dev)"
(kroki budowania, migracji i Tailwinda są wspólne).

## Struktura katalogu

```
imap-archiver/
├── app/ #(szkielet Symfony)
│   ├── .env #(commitowany: domyślne wartości + atrapy, NIE sekrety)
│   ├── .env.dev #(commitowany: domyślne wartości dla APP_ENV=dev)
│   ├── .env.local #(gitignorowany: sekrety aplikacji, np. APP_SECRET)
│   └── ... #(pozostałe pliki aplikacji: public/, src/, vendor/, ...)
├── frankenphp/
│   └── Caddyfile
├── .env #(gitignorowany: sekrety Compose, np. DB_PASSWORD — tylko prod)
├── .env.dist #(commitowany szablon sekretów Compose; skopiuj do /.env na prod)
├── CLAUDE.md
├── compose.yaml
├── Dockerfile
├── README.md
```

## Przydatne polecenia

```bash
docker compose exec php php bin/console <komenda>
docker compose exec php composer <komenda>
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose down       # zatrzymuje, wolumeny zostają
docker compose down -v    # UWAGA: kasuje wolumeny, w tym bazę
```

## Ciekawostki techniczne

Krótkie „dlaczego tak", które wychodzą przy budowie archiwizatora poczty.

### Nie każdy mail ma `Message-ID` — i nie każdy `Message-ID` jest unikalny

Mogłoby się wydawać, że `Message-ID` to idealny identyfikator wiadomości. Nie jest —
zawodzi w obie strony:

- **Bywa go brak.** Nagłówek `Message-ID` jest w normie (RFC 5322) **opcjonalny** —
  „powinien być", nie „musi być". Zwykle dokłada go program nadawcy albo pierwszy serwer
  po drodze, więc większość dostarczonych maili go ma. Ale skanując całą skrzynkę natkniesz
  się na wyjątki: **szkice** (ID nadawany dopiero przy wysyłce), maile z **prostych skryptów
  i automatów**, wiadomości **wgrane do skrzynki z importu** (`APPEND`), wreszcie **malformed
  i spam**.
- **Bywa zduplikowany.** Nawet gdy jest, norma tylko *zaleca* globalną unikalność. W praktyce
  ten sam `Message-ID` potrafi się powtórzyć (maile wysłane ponownie, błędne serwery).

Dlatego tożsamością wiadomości w tym projekcie jest **`sha256` surowych bajtów `.eml`** — zawsze
obecny i naprawdę jednoznaczny (te same bajty = ten sam mail). To na nim stoi idempotencja importu
(`UNIQUE(account_id, sha256)`): ponowny import nie zrobi duplikatu. `Message-ID` trzymamy obok
(opcjonalnie, zindeksowany) — przydaje się do **cross-referencji**, np. wątkowania po
`In-Reply-To`/`References`, ale nigdy do decyzji „czy tę wiadomość już mamy".

### Archiwum `.eml` to bind mount do katalogu hosta, nie wolumen Dockera

Surowe `.eml` to **źródło prawdy** — bazę odbudujemy z archiwum, nigdy odwrotnie. Dlatego nie
trzymamy ich w nazwanym wolumenie Dockera ani wewnątrz repo, tylko na **bind mouncie do katalogu
hosta poza repo** (`ARCHIVE_HOST_DIR`). Powody:

- **`docker compose down -v` kasuje nazwane wolumeny.** Jedno nieuważne `-v` i źródło prawdy
  znika. Bind mount do katalogu hosta to przeżywa.
- **`git clean -fdx` kasuje pliki ignorowane** — w tym katalog archiwum, gdyby leżał w repo.
  Sibling poza repo jest poza zasięgiem.
- **Backup i wgląd są trywialne** — to zwykłe pliki na dysku hosta: `rsync`, `cp`, `grep`,
  `sha256sum` działają wprost, bez `docker cp` czy kontenera-pomocnika.

Aplikacja nie zna ścieżki hosta — pisze zawsze do `/archive` (`ARCHIVE_DIR`). Dzięki temu na
produkcji przełożysz archiwum na inny dysk/NAS zmianą jednej zmiennej, bez dotykania kodu.

### Zapis `.eml` jest atomowy: najpierw `*.tmp`, potem `rename`

Zapis do archiwum idzie przez `Filesystem::dumpFile()`, które pisze do pliku tymczasowego w tym
samym katalogu i dopiero robi `rename`. `rename` w obrębie jednego systemu plików jest **atomowy**
(POSIX): docelowa ścieżka w jednej chwili przeskakuje na kompletny plik — nikt nigdy nie zobaczy
pliku zapisanego w połowie.

Gdybyśmy pisali wprost do `<sha256>.eml`, przerwanie w trakcie (crash, ubity kontener, pełny dysk)
zostawiłoby **obcięty plik pod kanoniczną ścieżką** — nazwa mówi „sha256 = X", a bajty hashują się
na coś innego. Uszkodzone źródło prawdy dokładnie tam, gdzie ma leżeć prawda. Przy `temp + rename`
crash zostawia co najwyżej osierocony `*.tmp` (nieszkodliwy), a kanoniczny plik pojawia się dopiero
kompletny. Warunek: temp musi być na tym samym FS co cel (stąd „w tym samym katalogu") — inaczej
`rename` degeneruje się do nieatomowego kopiowania.
