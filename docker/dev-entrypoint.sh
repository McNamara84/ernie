#!/bin/bash
# =============================================================================
# ERNIE Development Entrypoint Script
# =============================================================================
# This script runs during container startup in development mode
# =============================================================================

set -e

source "$(dirname "${BASH_SOURCE[0]}")/laravel-runtime.sh"

APP_PATH=${APP_PATH:-/var/www/html}
STORAGE_PATH="$APP_PATH/storage"

# An exported empty value would prevent Laravel's immutable dotenv loader from
# reading the shared .env, including after first-time key generation.
if [ -z "${APP_KEY:-}" ]; then
    unset APP_KEY
fi

echo "==================================="
echo "ERNIE Development Container Startup"
echo "==================================="

prepare_laravel_permissions

# Configure PHP-FPM to listen on all interfaces for Docker networking
echo "Configuring PHP-FPM..."
sed -i 's/listen = 127.0.0.1:9000/listen = 9000/' /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true

cd "$APP_PATH"

# Install composer dependencies if vendor is empty
if [ ! -f "$APP_PATH/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    # Composer scripts and plugins can boot Laravel, so installation must also
    # run as the web user. Limit the additional write access to dependencies.
    mkdir -p "$APP_PATH/vendor"
    chown -R www-data:www-data "$APP_PATH/vendor"
    run_as_www_data env COMPOSER_HOME="$STORAGE_PATH/framework/.config/composer" \
        composer install --no-interaction --optimize-autoloader
fi

# Install npm dependencies if node_modules is empty or doesn't exist
if [ ! -d "$APP_PATH/node_modules" ] || [ -z "$(ls -A "$APP_PATH/node_modules" 2>/dev/null)" ]; then
    echo "Installing NPM dependencies..."
    npm install
fi

prepare_storage_link

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

# Copy the shared environment and generate its key once under a file lock.
initialize_development_environment

# Clear caches for fresh start
echo "Clearing caches..."
run_as_www_data php artisan config:clear 2>/dev/null || true
run_as_www_data php artisan route:clear 2>/dev/null || true
run_as_www_data php artisan view:clear 2>/dev/null || true

# Remove production build assets in dev mode
# In dev mode, assets should be served by Vite dev server, not from build/
# If build/ exists from a previous production build, Laravel may use it as fallback
if [ -d "$APP_PATH/public/build" ]; then
    echo "Removing production build assets (dev mode uses Vite HMR)..."
    rm -rf "$APP_PATH/public/build"
fi

# Run migrations
echo "Running database migrations..."
MIGRATION_OK=false
if run_as_www_data php artisan migrate --force 2>/dev/null; then
    MIGRATION_OK=true
else
    echo "⚠ Migration failed - database might not be ready yet"
    echo "  Run 'docker exec --user www-data ernie-app-dev php artisan migrate' manually"
fi

# Seed lookup tables and test data if the database is empty
if [ "$MIGRATION_OK" = true ]; then
    RESOURCE_COUNT=$(run_as_www_data php artisan tinker --execute="echo \App\Models\Resource::count();" 2>/dev/null || echo "error")
    if [ "$RESOURCE_COUNT" = "0" ]; then
        echo "Database is empty – seeding lookup tables and test data..."
        run_as_www_data php artisan db:seed --force 2>/dev/null || {
            echo "⚠ DatabaseSeeder failed"
        }
        run_as_www_data php artisan db:seed --class=ResourceTestDataSeeder --force 2>/dev/null || {
            echo "⚠ ResourceTestDataSeeder failed"
        }
    elif [ "$RESOURCE_COUNT" = "error" ]; then
        echo "⚠ Could not check resource count – skipping seeding"
    fi
fi

echo "==================================="
echo "✓ Development container ready!"
echo "==================================="

exec_laravel_runtime "$@"
