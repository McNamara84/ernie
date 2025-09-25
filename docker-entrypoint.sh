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

    if [ "${DB_HOST:-}" != "" ]; then
        echo "Waiting for database to be ready..."
        # Simple wait for database port
        until nc -z -v -w30 "$DB_HOST" 3306; do
          echo "Waiting for database connection..."
          sleep 5
        done
        
        # Wait a bit more for MySQL to initialize
        echo "Database port is open, waiting for MySQL to initialize..."
        sleep 15
        
        # Try migration, but don't fail if it doesn't work immediately
        echo "Attempting database migration..."
        if ! php artisan migrate --force --no-interaction 2>/dev/null; then
            echo "Migration failed, will retry later. Container will continue to start."
            echo "You may need to run migrations manually after all containers are up."
        else
            echo "Migration successful!"
        fi
    fi

    if [ "${SKIP_STORAGE_LINK:-}" != "1" ]; then
        php artisan storage:link --force --no-interaction
    fi
else
    echo "Warning: artisan not found at $ARTISAN_BIN; skipping artisan commands" >&2
fi

exec "$@"