FROM dunglas/frankenphp:1-php8.4 AS frankenphp_upstream

### Base (extensions PHP, utilisateur non-root) ###
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN groupadd -g ${GROUP_ID} www && \
    useradd -u ${USER_ID} -g www -m -s /bin/bash www && \
    mkdir -p /home/www/.composer && \
    chown -R www:www /app /data /config /home/www/.composer

RUN install-php-extensions \
    @composer \
    intl \
    zip \
    opcache \
    apcu \
    pdo_pgsql \
    pcntl

COPY --link .docker/php/app.ini $PHP_INI_DIR/conf.d/

ENV COMPOSER_HOME=/home/www/.composer

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

### Dev ###
FROM frankenphp_base AS frankenphp_dev

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

USER www
