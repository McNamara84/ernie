FROM serversideup/php:8.4-fpm-nginx AS app

WORKDIR /var/www/html

# Install additional packages if needed (Node.js should already be available)
USER root
RUN apt-get update && apt-get install -y \
    netcat-traditional \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy configuration files
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy PHP configuration if needed
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Copy custom NGINX configuration for Laravel optimizations
COPY docker/nginx/conf.d/default.conf /etc/nginx/conf.d/default.conf

# Copy application files
COPY . /var/www/html

# Copy environment file for build
COPY .env.production /var/www/html/.env

# Install dependencies and build assets
RUN composer install --no-interaction --no-plugins --no-scripts \
    && npm install \
    && NODE_ENV=production npm run build \
    && rm -f public/hot

# Set proper permissions
RUN chown -R webuser:webgroup /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Switch back to webuser for security
USER webuser

EXPOSE 80
EXPOSE 443

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]