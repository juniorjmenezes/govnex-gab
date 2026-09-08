FROM node:22-bookworm-slim@sha256:f32b81066cde10a75dbac96646099533316d94bac4150c55da1636e1f0ffdc46 AS node
FROM composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d AS composer

FROM php:8.4-cli-bookworm@sha256:8f0c382c6483a202baaad042c9ad51064f050de1093e5e8db990d055dd18534f AS builder

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html
COPY . .

RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && rm -rf node_modules tests storage/logs/* \
    && ln -s ../storage/app/public public/storage

FROM php:8.4-apache-bookworm@sha256:721cfc216f3a029c635ae55b3a43fb5c22ac271871a5453f940d392ed223675d

ARG VCS_REF=unknown
ARG SOURCE_SHA256=unknown

LABEL org.opencontainers.image.title="GOVNEX GAB" \
      org.opencontainers.image.revision="$VCS_REF" \
      org.opencontainers.image.source-sha256="$SOURCE_SHA256"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_mysql zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY docker/production/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/production/apache-global.conf /etc/apache2/conf-available/govnexgab-global.conf
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-govnexgab-production.ini
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/govnexgab.conf
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

RUN a2enconf govnexgab-global

WORKDIR /var/www/html

# supervisord mantém o Apache, o scheduler (php artisan schedule:work) e os
# workers das filas default/tse/whatsapp rodando juntos neste container. Sem
# isso, sincronizações do TSE/PollingData, envio de WhatsApp, exportações de
# relatório e lembretes agendados ficam presos em "pendente" para sempre —
# nada além do Apache processa fila ou scheduler.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/govnexgab.conf", "-n"]
