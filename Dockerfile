FROM php:8.5-fpm AS app

WORKDIR /var/www/html

# Install system dependencies and Node.js in a single layer for efficiency.
# Node.js 24 is installed from NodeSource with GPG verification for supply chain security.
# The NodeSource package includes npm, so no separate npm installation is needed.
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
    netcat-traditional \
    ca-certificates \
    gnupg \
    # Install Node.js 24 from NodeSource with GPG key verification.
    # This approach is more secure than piping remote scripts to bash.
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sodium xsl intl sockets

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# PHP-FPM pool configuration for optimized worker management
# This prevents 502 errors on pages with large Inertia payloads
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

COPY docker/certs/sumariopmd-ca.crt /usr/local/share/ca-certificates/sumariopmd-ca.crt
RUN update-ca-certificates

# Copy dependency files FIRST (this layer is cached unless dependencies change)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-plugins --no-scripts \
    && composer dump-autoload --optimize --no-scripts

# Copy package files and install node dependencies (cached unless package.json changes)
COPY package.json package-lock.json ./
RUN npm install

# NOW copy the rest of the application (this changes frequently)
COPY . /var/www/html

# Copy environment file for build
COPY .env.production /var/www/html/.env

# Clear any cached config files that might reference old packages
RUN rm -rf bootstrap/cache/*.php bootstrap/cache/packages.php bootstrap/cache/services.php \
    && mkdir -p bootstrap/cache \
    && chmod -R 775 bootstrap/cache

# Build frontend assets
RUN NODE_ENV=production npm run build \
    && rm -f public/hot

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:alpine AS nginx

WORKDIR /var/www/html

COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
COPY --from=app /var/www/html/storage /var/www/html/storage