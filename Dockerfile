FROM composer:latest as composer
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --ignore-platform-reqs

FROM node:18 as node
WORKDIR /app
COPY package*.json ./

RUN if [ -f "package.json" ]; then \
        npm ci || npm install; \
    fi
COPY . .

RUN if [ -f "package.json" ]; then \
        npm run build || \
        npm run production || \
        npm run prod || \
        echo "No build script found, skipping..."; \
    fi

FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

COPY --from=composer /app/vendor ./vendor

COPY . .

COPY --from=node /app/public/build ./public/build/
COPY --from=node /app/public/js ./public/js/
COPY --from=node /app/public/css ./public/css/

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80