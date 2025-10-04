FROM php:8.4-fpm AS app

WORKDIR /var/www/html

# Install system dependencies (this layer rarely changes)
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
    netcat-traditional \
    ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (this layer rarely changes)
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sodium xsl intl \
    && pecl install redis \
    && docker-php-ext-enable redis

# Copy composer from official image
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy configuration files (these rarely change)
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

COPY docker/certs/sumariopmd-ca.crt /usr/local/share/ca-certificates/sumariopmd-ca.crt
RUN update-ca-certificates

# Copy all application files first
# node_modules and vendor are excluded via .dockerignore
COPY . /var/www/html

# Copy environment file for build
COPY .env.production /var/www/html/.env

# Clear any cached config files
RUN rm -rf bootstrap/cache/*.php bootstrap/cache/packages.php bootstrap/cache/services.php \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 bootstrap/cache

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative

# Install Node dependencies and build (use npm install instead of npm ci for flexibility)
RUN npm install \
    && NODE_ENV=production npm run build \
    && rm -f public/hot

# Clean up node_modules to reduce image size
RUN rm -rf node_modules \
    && npm cache clean --force

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:alpine AS nginx

WORKDIR /var/www/html

COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/storage /var/www/html/storage