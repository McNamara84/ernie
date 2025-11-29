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
