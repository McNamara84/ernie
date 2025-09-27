# Build stage for assets
FROM node:20-alpine AS assets-builder

WORKDIR /app

# Install build dependencies
RUN apk add --no-cache python3 make g++

# Copy package files for better caching
COPY package*.json ./

# Install dependencies (including devDependencies needed for build)
RUN npm ci --include=dev

# Copy source files needed for build
COPY resources/ ./resources/
COPY vite.config.ts vite.build.config.ts tsconfig.json ./

# Create public directory for Vite
RUN mkdir -p public

# Build assets using build-specific config
RUN NODE_ENV=production npx vite build --config vite.build.config.ts

# Main application stage - using serversideup/php
FROM serversideup/php:8.4-fpm-nginx AS app

# Work as root initially
USER root

# Install additional PHP extensions
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libxslt1-dev \
    libicu-dev \
    && docker-php-ext-install bcmath xsl intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure Laravel automations via environment variables
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_CONFIG_CACHE=true \
    AUTORUN_LARAVEL_ROUTE_CACHE=true \
    AUTORUN_LARAVEL_VIEW_CACHE=true \
    AUTORUN_LARAVEL_EVENT_CACHE=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    AUTORUN_LARAVEL_MIGRATION=false \
    LOG_OUTPUT_LEVEL=info \
    PHP_OPCACHE_ENABLE=1 \
    NGINX_WEBROOT=/var/www/html/public

WORKDIR /var/www/html

# Copy application files to a backup location that won't be overridden
RUN mkdir -p /tmp/laravel-backup
COPY . /tmp/laravel-backup/

# Copy built assets from assets-builder stage to backup location
COPY --from=assets-builder /app/public/build /tmp/laravel-backup/public/build

# Copy environment file to backup location
COPY .env.production /tmp/laravel-backup/.env

# Create a script to copy files at runtime
RUN echo '#!/bin/bash' > /usr/local/bin/setup-laravel.sh \
    && echo 'echo "=== Setting up Laravel application ==="' >> /usr/local/bin/setup-laravel.sh \
    && echo 'ls -la /tmp/laravel-backup/' >> /usr/local/bin/setup-laravel.sh \
    && echo 'cp -R /tmp/laravel-backup/* /var/www/html/' >> /usr/local/bin/setup-laravel.sh \
    && echo 'cp -R /tmp/laravel-backup/.* /var/www/html/ 2>/dev/null || true' >> /usr/local/bin/setup-laravel.sh \
    && echo 'chown -R www-data:www-data /var/www/html' >> /usr/local/bin/setup-laravel.sh \
    && echo 'chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true' >> /usr/local/bin/setup-laravel.sh \
    && echo 'ls -la /var/www/html/' >> /usr/local/bin/setup-laravel.sh \
    && echo 'ls -la /var/www/html/artisan 2>/dev/null || echo "artisan not found"' >> /usr/local/bin/setup-laravel.sh \
    && chmod +x /usr/local/bin/setup-laravel.sh

# Add this script to the entrypoint.d directory so it runs before Laravel automations
RUN echo '#!/bin/bash' > /etc/entrypoint.d/10-setup-laravel.sh \
    && echo '/usr/local/bin/setup-laravel.sh' >> /etc/entrypoint.d/10-setup-laravel.sh \
    && echo 'cd /var/www/html && composer install --no-interaction --no-dev --optimize-autoloader --ignore-platform-req=ext-pail' >> /etc/entrypoint.d/10-setup-laravel.sh \
    && echo 'composer dump-autoload --optimize' >> /etc/entrypoint.d/10-setup-laravel.sh \
    && chmod +x /etc/entrypoint.d/10-setup-laravel.sh

# Copy custom configs if they exist
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Switch back to www-data user for security
USER www-data