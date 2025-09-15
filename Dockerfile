# ============================================
# Build Stage für Node/React
# ============================================
FROM node:20-alpine AS node-builder

WORKDIR /app

# Package files kopieren und installieren
COPY package*.json ./
RUN npm ci

# App-Dateien kopieren und Build ausführen
COPY . .
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
    intl

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