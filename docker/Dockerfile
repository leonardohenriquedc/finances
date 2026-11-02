# syntax=docker/dockerfile:1
FROM php:8.3-fpm-bookworm AS php

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libonig-dev libsqlite3-dev libxml2-dev libgmp-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_sqlite mbstring intl zip bcmath gmp pcntl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid "${LOCAL_GID}" app \
    && useradd --uid "${LOCAL_UID}" --gid app --create-home --shell /bin/sh app \
    && mkdir -p /var/www/html/vendor \
    && chown -R app:app /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/development.ini /usr/local/etc/php/conf.d/99-development.ini
COPY --chmod=0755 docker/setup.sh /usr/local/bin/finances-setup

WORKDIR /var/www/html
USER app
CMD ["php-fpm", "-F"]

FROM node:22-bookworm-slim AS frontend

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

RUN groupmod --gid "${LOCAL_GID}" node \
    && usermod --uid "${LOCAL_UID}" --gid "${LOCAL_GID}" node \
    && mkdir -p /var/www/html/node_modules \
    && chown -R node:node /var/www/html /home/node

COPY --chmod=0755 docker/frontend.sh /usr/local/bin/finances-frontend
WORKDIR /var/www/html
USER node
CMD ["finances-frontend"]
