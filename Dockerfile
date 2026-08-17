# Obraz FrankenPHP zawiera już PHP i serwer (Caddy).
# Możesz przypiąć wersję PHP, np. dunglas/frankenphp:php8.4
FROM dunglas/frankenphp:latest

# Rozszerzenia PHP potrzebne pod Symfony i Postgresa.
# install-php-extensions jest dostarczone w obrazie FrankenPHP.
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    opcache \
    zip

# Chromium + driver pod testy w przeglądarce headless (symfony/panther, etap 4.3d).
# Turbo i Live Components to JS — WebTestCase widzi wyłącznie HTML wysłany przez serwer,
# więc zachowania po stronie klienta nie da się bez przeglądarki zmierzyć w ogóle.
#
# Oba pakiety idą z apt JEDNYM poleceniem specjalnie po to, żeby wersje driver-a i przeglądarki
# dobrał menedżer pakietów. Ręczne pobieranie ChromeDriver-a (np. przez `dbrekelmans/bdi`)
# rozjeżdża się z przeglądarką przy każdej aktualizacji obrazu, a objaw jest mylący:
# "session not created: This version of ChromeDriver only supports Chrome version N".
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium chromium-driver \
    && rm -rf /var/lib/apt/lists/*

# Composer (kopiujemy gotowy binarny z oficjalnego obrazu).
COPY --from=composer/composer:2-bin --link /composer /usr/bin/composer

WORKDIR /app
