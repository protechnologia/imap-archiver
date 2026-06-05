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

# Composer (kopiujemy gotowy binarny z oficjalnego obrazu).
COPY --from=composer/composer:2-bin --link /composer /usr/bin/composer

WORKDIR /app
