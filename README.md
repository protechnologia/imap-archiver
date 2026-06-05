# imap-archiver

Aplikacja do archiwizacji poczty z serwera IMAP: pobiera wiadomości (np. z konkretnego
roku), zapisuje surowe `.eml` jako źródło prawdy, indeksuje je w bazie do podglądu
i wyszukiwania, i opcjonalnie kasuje z serwera po świadomym potwierdzeniu admina.

Plan etapów, architektura i zasady projektu: zobacz [`CLAUDE.md`](./CLAUDE.md).

## Wymagania

- Docker + Docker Compose
- Opcjonalnie: lokalny PHP 8.x + Composer (inaczej użyjemy kontenera)

## Stack

Symfony 7 · FrankenPHP · PostgreSQL 16 · Symfony UX (Turbo + Stimulus + Live Components) ·
Tailwind (AssetMapper, bez Node) · EasyAdmin · Docker.

## Pierwsze uruchomienie (etap 0)

1. **Wygeneruj szkielet Symfony.** Z lokalnym Composerem:

   ```bash
   composer create-project symfony/skeleton:"7.*" imap-archiver && cd imap-archiver
   ```

   Bez lokalnego PHP — kontenerem:

   ```bash
   docker run --rm -v "$PWD":/app -w /app composer:2 \
     create-project symfony/skeleton:"7.*" imap-archiver && cd imap-archiver
   ```

2. **Skopiuj pliki Dockera** do projektu: `compose.yaml` i `Dockerfile` do korzenia,
   `Caddyfile` do podfolderu `frankenphp/`.

3. **Zbuduj i wstań:**

   ```bash
   docker compose up -d --build
   ```

   Postgres ma healthcheck, więc `php` ruszy dopiero, gdy baza będzie gotowa.

4. **Podłącz Doctrine** (żeby `DATABASE_URL` był realnie używany):

   ```bash
   docker compose exec php composer require symfony/orm-pack
   ```

5. **Smoke test — sprawdź, że całość żyje:**

   ```bash
   docker compose exec php php bin/console doctrine:query:sql "SELECT 1"
   docker compose exec php php bin/console about
   ```

6. **Otwórz** `http://localhost:8080`. Świeży skeleton bez tras zwróci 404 — to normalne;
   istotne, że odpowiada Symfony, a nie błąd serwera. Pierwszą trasę (`/health`) dodajemy
   na etapie 1.

## Struktura katalogu

```
imap-archiver/
├── compose.yaml
├── Dockerfile
├── frankenphp/
│   └── Caddyfile
├── CLAUDE.md
├── README.md
└── (szkielet Symfony: public/, src/, vendor/, .env, ...)
```

## Przydatne polecenia

```bash
docker compose exec php php bin/console <komenda>
docker compose exec php composer <komenda>
docker compose exec php php bin/console doctrine:migrations:migrate
docker compose down       # zatrzymuje, wolumeny zostają
docker compose down -v    # UWAGA: kasuje wolumeny, w tym bazę
```
