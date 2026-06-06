# imap-archiver

Aplikacja do archiwizacji poczty z serwera IMAP: pobiera wiadomości (np. z konkretnego
roku), zapisuje surowe `.eml` jako źródło prawdy, indeksuje je w bazie do podglądu
i wyszukiwania, i opcjonalnie kasuje z serwera po świadomym potwierdzeniu admina.

Plan etapów, architektura i zasady projektu: zobacz [`CLAUDE.md`](./CLAUDE.md).

## Wymagania

- Docker + Docker Compose
- Opcjonalnie: lokalny PHP 8.x + Composer (inaczej użyjemy kontenera)

> Szkielet Symfony jest już w repo (`app/`) — nie trzeba go generować. Wystarczy
> sklonować i wstać.

## Stack

Symfony 7.4 (LTS) · FrankenPHP · PostgreSQL 16 · Symfony UX (Turbo + Stimulus + Live
Components) · Tailwind (AssetMapper, bez Node) · EasyAdmin · Docker.

## Pierwsze uruchomienie

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

## Struktura katalogu

```
imap-archiver/
├── app/ #(szkielet Symfony: public/, src/, vendor/, .env, ...)
├── frankenphp/
│   └── Caddyfile
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
