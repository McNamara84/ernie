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

if [ -f "$ENV_FILE" ] && [ -z "$(grep '^APP_KEY=' "$ENV_FILE" | grep -v '=$')" ]; then
    php artisan key:generate
elif [ ! -f "$ENV_FILE" ]; then
    echo "Warning: skipping APP_KEY generation because $ENV_FILE is missing" >&2
fi

if [ "$DB_HOST" != "" ]; then
    until nc -z -v -w30 "$DB_HOST" 3306; do
      echo "Waiting for database connection..."
      sleep 5
    done

    php artisan migrate --force
fi

exec "$@"