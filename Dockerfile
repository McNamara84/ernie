FROM php:8.5.9-fpm-trixie@sha256:32ef9f35b567a741f24c5d2c3312f803fe6c9e34b7db46212f95fce675e1d13f AS app-base

WORKDIR /var/www/html

ARG PHP_REDIS_VERSION=6.3.0

# Install system dependencies needed for the PHP runtime and extension builds.
# LEGACY_DATABASE_DUMP_SUPPORT:
# Required for the admin-only /database dump page while legacy MySQL 5.6/5.7 exports exist.
# Remove this package when legacy database exports are retired.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y \
    libnghttp2-14 \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libsodium-dev \
    libxslt1-dev \
    libicu-dev \
    g++ \
    netcat-traditional \
    default-mysql-client \
    ca-certificates \
    libssl3t64 \
    openssl \
    openssl-provider-legacy \
    gnupg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sodium xsl intl sockets

# Install Redis extension from the official phpredis source tag.
RUN set -eux; \
    curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/${PHP_REDIS_VERSION}.tar.gz" -o /tmp/phpredis.tar.gz; \
    mkdir -p /usr/src/php/ext/redis; \
    tar -xzf /tmp/phpredis.tar.gz -C /usr/src/php/ext/redis --strip-components=1; \
    docker-php-ext-install redis; \
    rm -rf /tmp/phpredis.tar.gz /usr/src/php/ext/redis

COPY --from=composer:2.10.2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/bin/composer

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# PHP-FPM pool configuration for optimized worker management
# This prevents 502 errors on pages with large Inertia payloads
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

COPY docker/certs/sumariopmd-ca.crt /usr/local/share/ca-certificates/sumariopmd-ca.crt
RUN update-ca-certificates

FROM app-base AS app-build

# Install Node.js only in the build stage so the runtime image contains no Node package manifests.
# The Node.js version comes from .node-version; NodeSource only needs the numeric major.
COPY .node-version /tmp/.node-version
RUN NODE_VERSION="$(tr -d '\r\n' < /tmp/.node-version)" \
    && NODE_VERSION="${NODE_VERSION#v}" \
    && NODE_MAJOR="${NODE_VERSION%%.*}" \
    && case "$NODE_MAJOR" in ''|*[!0-9]*) echo "Expected .node-version to start with a numeric Node.js major version, got: $NODE_VERSION" >&2; exit 1 ;; esac \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy dependency files FIRST (this layer is cached unless dependencies change)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-plugins --no-scripts \
    && composer dump-autoload --optimize --no-scripts

# Copy package files and install node dependencies (cached unless package.json changes)
COPY package.json package-lock.json .npmrc ./
RUN npm ci

# NOW copy the rest of the application (this changes frequently)
COPY . /var/www/html

# Copy environment file for build
COPY .env.production /var/www/html/.env

# Clear any cached config files that might reference old packages
RUN rm -rf bootstrap/cache/*.php bootstrap/cache/packages.php bootstrap/cache/services.php \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 bootstrap/cache

# Wayfinder boots Laravel while Vite builds the frontend. Keep that build step
# independent of runtime services; the copied production environment remains
# unchanged for the resulting application image.
RUN APP_ENV=production \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    QUEUE_FAILED_DRIVER=null \
    BROADCAST_CONNECTION=log \
    BROADCAST_DRIVER=log \
    NODE_ENV=production \
    npm run build \
    && rm -f public/hot \
    && rm -rf node_modules /root/.npm /root/.cache \
    && rm -f package.json package-lock.json .npmrc

FROM app-base AS app

COPY --from=app-build /var/www/html /var/www/html

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.31.3-alpine@sha256:4a73073bd557c65b759505da037898b61f1be6cbcc3c2c3aeac22d2a470c1752 AS nginx

WORKDIR /var/www/html

COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/storage /var/www/html/storage
RUN mkdir -p /var/www/html/public \
    && rm -rf /var/www/html/public/storage \
    && ln -s ../storage/app/public /var/www/html/public/storage
