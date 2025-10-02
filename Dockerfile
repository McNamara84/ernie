FROM php:8.4-fpm AS app

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
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
    nodejs \
    npm \
    netcat-traditional

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sodium xsl intl

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Provide the SUMARIOPMD certificate for secure database connections
COPY docker/certs/sumariopmd-ca.pem /etc/ssl/certs/sumariopmd-ca.pem
ENV DB_SUMARIOPMD_SSL_CA=/etc/ssl/certs/sumariopmd-ca.pem

COPY . /var/www/html

# Copy environment file for build
COPY .env.production /var/www/html/.env

RUN composer install --no-interaction --no-plugins --no-scripts \
    && npm install \
    && NODE_ENV=production npm run build \
    && rm -f public/hot

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:alpine AS nginx

WORKDIR /var/www/html

COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/storage /var/www/html/storage