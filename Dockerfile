# ============================================
# Build Stage für Node/React
# ============================================
FROM node:20-bookworm-slim AS node-builder

# Benötigte PHP-CLI Pakete für Wayfinder-Generierung installieren
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        php-cli \
        php-mbstring \
        php-xml \
        php-zip \
        php-intl \
        php-bcmath \
        php-gd \
        php-curl \
        php-mysql; \
    PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"; \
    if ! php -m | grep -qi sodium; then \
        if apt-cache show "php${PHP_MAJOR_MINOR}-sodium" >/dev/null 2>&1; then \
            apt-get install -y --no-install-recommends "php${PHP_MAJOR_MINOR}-sodium"; \
        elif apt-cache show php-sodium >/dev/null 2>&1; then \
            apt-get install -y --no-install-recommends php-sodium; \
        else \
            echo "Sodium extension not available for PHP ${PHP_MAJOR_MINOR}" >&2; \
            exit 1; \
        fi; \
    fi; \
    if ! php -m | grep -qi xsl; then \
        if apt-cache show "php${PHP_MAJOR_MINOR}-xsl" >/dev/null 2>&1; then \
            apt-get install -y --no-install-recommends "php${PHP_MAJOR_MINOR}-xsl"; \
        elif apt-cache show php-xsl >/dev/null 2>&1; then \
            apt-get install -y --no-install-recommends php-xsl; \
        else \
            echo "XSL extension not available for PHP ${PHP_MAJOR_MINOR}" >&2; \
            exit 1; \
        fi; \
    fi; \
    php -m | grep -qi sodium; \
    php -m | grep -qi xsl; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Composer bereitstellen
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Package und Composer Dateien kopieren
COPY package*.json composer.json composer.lock ./

# Dependencies inklusive optionaler Native-Binaries installieren
# (wichtig für Rollup, Lightning CSS & Tailwind Oxide in CI-Umgebungen)
RUN npm ci --include=optional --verbose || npm install --include=optional --verbose

# Alle App-Dateien kopieren
COPY . .

# Laravel für Wayfinder vorbereiten und Composer Dependencies installieren
RUN set -eux; \
    if [ ! -f .env ]; then cp .env.example .env; fi; \
    mkdir -p storage/framework/{cache,sessions,views}; \
    mkdir -p storage/logs; \
    mkdir -p storage/app/public; \
    composer install \
        --no-interaction \
        --no-dev \
        --prefer-dist \
        --optimize-autoloader

# Debug: Verfügbare npm scripts anzeigen
RUN echo "Available npm scripts:" && npm run

# Build ausführen mit Fallback
RUN npm run build

# ============================================
# Production Stage
# ============================================
FROM php:8.4-fpm AS production

# System-Dependencies und Composer installieren
RUN apt-get update && apt-get install -y \
    curl \
    git \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libxslt1-dev \
    libsodium-dev \
    zip \
    supervisor \
    mariadb-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP Extensions installieren
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache \
    intl \
    sodium \
    xsl

# Redis Extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer installieren
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# OPcache konfigurieren
RUN { \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# User erstellen
RUN groupadd -g 1000 www && \
    useradd -u 1000 -ms /bin/bash -g www www

WORKDIR /var/www/html

# App-Dateien kopieren
COPY --chown=www:www . .

# Composer Dependencies installieren
RUN composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist

# Node Build-Artefakte kopieren
COPY --from=node-builder --chown=www:www /app/public/build ./public/build

# Storage Verzeichnisse vorbereiten
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public \
    && mkdir -p bootstrap/cache \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Entrypoint Script
COPY --chown=www:www docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

USER www

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]