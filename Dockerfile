# ============================================
# Build Stage für Node/React
# ============================================
FROM node:20-alpine AS node-builder

WORKDIR /app

# Package files kopieren
COPY package*.json ./
COPY yarn.lock* ./
COPY pnpm-lock.yaml* ./

# Dependencies installieren
RUN if [ -f yarn.lock ]; then yarn install --frozen-lockfile; \
    elif [ -f pnpm-lock.yaml ]; then corepack enable && pnpm install --frozen-lockfile; \
    else npm ci; fi

# App-Dateien kopieren und Build ausführen
COPY . .
RUN if [ -f yarn.lock ]; then yarn build; \
    elif [ -f pnpm-lock.yaml ]; then pnpm build; \
    else npm run build; fi

# ============================================
# Composer Dependencies Stage
# ============================================
FROM php:8.4-fpm AS composer-builder

# System-Dependencies für Composer installieren
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP Extensions installieren die Composer/Laravel braucht
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl

# Composer installieren
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Erst composer.json und composer.lock kopieren (falls vorhanden)
COPY composer.json ./
COPY composer.lock* ./

# Auth file kopieren falls vorhanden (für private Repositories)
COPY auth.json* ./

# Dependencies installieren - mit Fehlerbehandlung für fehlende lock-Datei
RUN if [ -f composer.lock ]; then \
        composer install \
            --no-interaction \
            --no-dev \
            --no-scripts \
            --no-autoloader \
            --prefer-dist \
            --optimize-autoloader; \
    else \
        composer install \
            --no-interaction \
            --no-dev \
            --no-scripts \
            --no-autoloader \
            --prefer-dist; \
    fi

# Rest der App-Dateien kopieren
COPY . .

# Autoloader generieren und optimieren
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ============================================
# Production Stage
# ============================================
FROM php:8.4-fpm AS production

# System-Dependencies installieren
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    nginx \
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

# Redis Extension installieren
RUN pecl install redis && docker-php-ext-enable redis

# OPcache konfigurieren
RUN { \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.jit=tracing'; \
    echo 'opcache.jit_buffer_size=100M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# PHP Konfiguration für Production
RUN { \
    echo 'expose_php = Off'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /var/log/php/error.log'; \
    echo 'upload_max_filesize = 50M'; \
    echo 'post_max_size = 50M'; \
    echo 'max_execution_time = 60'; \
    echo 'memory_limit = 512M'; \
    } > /usr/local/etc/php/conf.d/production.ini

# User für Laravel erstellen
RUN groupadd -g 1000 www && \
    useradd -u 1000 -ms /bin/bash -g www www

# Working Directory setzen
WORKDIR /var/www/html

# App-Dateien vom Composer Builder kopieren
COPY --from=composer-builder --chown=www:www /app .

# Build-Artefakte vom Node Builder kopieren
COPY --from=node-builder --chown=www:www /app/public/build ./public/build
COPY --from=node-builder --chown=www:www /app/node_modules ./node_modules

# Storage und Cache Verzeichnisse vorbereiten
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && mkdir -p /var/log/php \
    && chown -R www:www storage bootstrap/cache /var/log/php \
    && chmod -R 775 storage bootstrap/cache

# Entrypoint Script kopieren
COPY --chown=www:www docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# User wechseln
USER www

# Ports exponieren
EXPOSE 9000

# Entrypoint und Command setzen
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]