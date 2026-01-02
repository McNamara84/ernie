#!/bin/bash
# =============================================================================
# ERNIE Development Entrypoint Script
# =============================================================================
# This script runs during container startup in development mode
# =============================================================================

set -e

APP_PATH=${APP_PATH:-/var/www/html}
STORAGE_PATH="$APP_PATH/storage"
FRAMEWORK_PATH="$STORAGE_PATH/framework"

echo "==================================="
echo "ERNIE Development Container Startup"
echo "==================================="

# Create required directories
mkdir -p "$FRAMEWORK_PATH/cache"
mkdir -p "$FRAMEWORK_PATH/sessions"
mkdir -p "$FRAMEWORK_PATH/views"
mkdir -p "$STORAGE_PATH/app/public"
mkdir -p "$APP_PATH/bootstrap/cache"

# Set permissions
chown -R www-data:www-data "$STORAGE_PATH" "$APP_PATH/bootstrap/cache" 2>/dev/null || true
chmod -R 775 "$STORAGE_PATH" "$APP_PATH/bootstrap/cache" 2>/dev/null || true

# Configure PHP-FPM to listen on all interfaces for Docker networking
echo "Configuring PHP-FPM..."
sed -i 's/listen = 127.0.0.1:9000/listen = 9000/' /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true

cd "$APP_PATH"

# Install composer dependencies if vendor is empty
if [ ! -f "$APP_PATH/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

# Install npm dependencies if node_modules is empty or doesn't exist
if [ ! -d "$APP_PATH/node_modules" ] || [ -z "$(ls -A "$APP_PATH/node_modules" 2>/dev/null)" ]; then
    echo "Installing NPM dependencies..."
    npm install
fi

# Create .env if not exists
if [ ! -f "$APP_PATH/.env" ]; then
    if [ -f "$APP_PATH/.env.docker" ]; then
        echo "Copying .env.docker to .env..."
        cp "$APP_PATH/.env.docker" "$APP_PATH/.env"
    elif [ -f "$APP_PATH/.env.example" ]; then
        echo "Copying .env.example to .env..."
        cp "$APP_PATH/.env.example" "$APP_PATH/.env"
    fi
fi

# Wait for database
if [ "${DB_HOST:-}" != "" ]; then
    echo "Waiting for database at $DB_HOST:3306..."
    MAX_WAIT=60
    WAITED=0
    while [ $WAITED -lt $MAX_WAIT ]; do
        if nc -z -w5 "$DB_HOST" 3306 2>/dev/null; then
            echo "✓ Database is available"
            break
        fi
        echo "  Waiting... (${WAITED}s/${MAX_WAIT}s)"
        sleep 2
        WAITED=$((WAITED + 2))
    done
fi

# Clear caches for fresh start
echo "Clearing caches..."
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

# Remove production build assets in dev mode
# In dev mode, assets should be served by Vite dev server, not from build/
# If build/ exists from a previous production build, Laravel may use it as fallback
if [ -d "$APP_PATH/public/build" ]; then
    echo "Removing production build assets (dev mode uses Vite HMR)..."
    rm -rf "$APP_PATH/public/build"
fi

# Generate app key if not set
if ! grep -q "^APP_KEY=base64:" "$APP_PATH/.env" 2>/dev/null; then
    echo "Generating application key..."
    php artisan key:generate --force
fi

# Run migrations
echo "Running database migrations..."
php artisan migrate --force 2>/dev/null || {
    echo "⚠ Migration failed - database might not be ready yet"
    echo "  Run 'docker exec ernie-app-dev php artisan migrate' manually"
}

# Create storage link
php artisan storage:link --force 2>/dev/null || true

echo "==================================="
echo "✓ Development container ready!"
echo "==================================="

exec "$@"
