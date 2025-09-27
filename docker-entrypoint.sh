#!/bin/bash
set -e

APP_PATH=${APP_PATH:-/var/www/html}
STORAGE_PATH="$APP_PATH/storage"
FRAMEWORK_PATH="$STORAGE_PATH/framework"
CACHE_PATH="$FRAMEWORK_PATH/cache"
SESSIONS_PATH="$FRAMEWORK_PATH/sessions"
VIEWS_PATH="$FRAMEWORK_PATH/views"
PUBLIC_PATH="$STORAGE_PATH/app/public"
BOOTSTRAP_CACHE="$APP_PATH/bootstrap/cache"
ENV_FILE="$APP_PATH/.env"
ENV_EXAMPLE_FILE="$APP_PATH/.env.example"
ENV_PRODUCTION_FILE="$APP_PATH/.env.production"
ARTISAN_BIN="$APP_PATH/artisan"

mkdir -p "$CACHE_PATH"
mkdir -p "$SESSIONS_PATH"
mkdir -p "$VIEWS_PATH"
mkdir -p "$PUBLIC_PATH"
mkdir -p "$BOOTSTRAP_CACHE"

chown -R www-data:www-data "$STORAGE_PATH" "$BOOTSTRAP_CACHE"
chmod -R 775 "$STORAGE_PATH" "$BOOTSTRAP_CACHE"

cd "$APP_PATH"

if [ ! -f "$ENV_FILE" ]; then
    if [ -f "$ENV_EXAMPLE_FILE" ]; then
        cp "$ENV_EXAMPLE_FILE" "$ENV_FILE"
    elif [ -f "$ENV_PRODUCTION_FILE" ]; then
        cp "$ENV_PRODUCTION_FILE" "$ENV_FILE"
    else
        echo "Warning: no environment template found at $ENV_EXAMPLE_FILE or $ENV_PRODUCTION_FILE" >&2
    fi
fi

if [ -f "$ARTISAN_BIN" ]; then
    # In production, we use environment variables instead of .env file
    if [ "${APP_KEY:-}" = "" ]; then
        echo "Info: APP_KEY not set, generating new key..."
        php artisan key:generate --force --no-interaction
    else
        echo "Info: using APP_KEY from environment"
    fi

    # Database migration with timeout and retry logic
    if [ "${DB_HOST:-}" != "" ]; then
        echo "Database configured: ${DB_HOST}:3306"
        echo "Attempting automatic migration..."
        
        # Wait for database port with timeout
        MAX_WAIT=120  # 2 minutes total wait time
        WAITED=0
        
        while [ $WAITED -lt $MAX_WAIT ]; do
            if nc -z -w5 "$DB_HOST" 3306 2>/dev/null; then
                echo "Database port is open"
                break
            fi
            echo "Waiting for database connection... (${WAITED}s/${MAX_WAIT}s)"
            sleep 5
            WAITED=$((WAITED + 5))
        done
        
        if [ $WAITED -ge $MAX_WAIT ]; then
            echo "WARNING: Database connection timeout after ${MAX_WAIT}s"
            echo "Container will start but migration may fail"
        fi
        
        # Additional wait for MySQL initialization
        echo "Waiting for MySQL to fully initialize..."
        sleep 20
        
        # Try migration with limited retries
        MIGRATION_ATTEMPTS=0
        MAX_MIGRATION_ATTEMPTS=3
        
        while [ $MIGRATION_ATTEMPTS -lt $MAX_MIGRATION_ATTEMPTS ]; do
            echo "Migration attempt $((MIGRATION_ATTEMPTS + 1))/${MAX_MIGRATION_ATTEMPTS}..."
            
            if php artisan migrate --force --no-interaction 2>/dev/null; then
                echo "✓ Migration successful!"
                break
            else
                MIGRATION_ATTEMPTS=$((MIGRATION_ATTEMPTS + 1))
                if [ $MIGRATION_ATTEMPTS -lt $MAX_MIGRATION_ATTEMPTS ]; then
                    echo "Migration failed, retrying in 10 seconds..."
                    sleep 10
                else
                    echo "WARNING: Migration failed after ${MAX_MIGRATION_ATTEMPTS} attempts"
                    echo "Container will continue to start"
                    echo "Check database connection and run 'docker exec <container> php artisan migrate' manually"
                fi
            fi
        done
    fi

    if [ "${SKIP_STORAGE_LINK:-}" != "1" ]; then
        php artisan storage:link --force --no-interaction
    fi
else
    echo "Warning: artisan not found at $ARTISAN_BIN; skipping artisan commands" >&2
fi

exec "$@"