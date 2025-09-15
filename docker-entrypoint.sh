#!/bin/bash
set -e

# Generiere APP_KEY nur wenn nicht gesetzt
if [ -z "$APP_KEY" ]; then
    echo "APP_KEY not set, generating new key..."
    php artisan key:generate --show > /tmp/app_key
    export APP_KEY=$(cat /tmp/app_key)
    echo "Generated APP_KEY: $APP_KEY"
    echo "⚠️  WARNUNG: Füge diesen Key zu deiner stack.env hinzu!"
fi

# Cache-Optimierungen (optional)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Starte Apache
exec "$@"