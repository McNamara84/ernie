#!/bin/sh
set -e

echo "🚀 Starting Laravel Application..."

# Funktion für Datenbankverbindung prüfen
wait_for_db() {
    echo "⏳ Waiting for MariaDB..."
    maxcounter=30
    counter=1
    while [ $counter -le $maxcounter ]; do
        if php -r "
            \$conn = @new mysqli(
                '${DB_HOST}',
                '${DB_USERNAME}',
                '${DB_PASSWORD}',
                '${DB_DATABASE}',
                ${DB_PORT}
            );
            if (\$conn->connect_error) {
                exit(1);
            }
            \$conn->close();
            exit(0);
        " 2>/dev/null; then
            echo "✅ MariaDB is ready!"
            break
        fi
        echo "MariaDB is unavailable - sleeping (attempt $counter/$maxcounter)"
        sleep 2
        counter=$((counter + 1))
    done

    if [ $counter -gt $maxcounter ]; then
        echo "❌ Failed to connect to MariaDB"
        exit 1
    fi
}

# Funktion für Redis prüfen
wait_for_redis() {
    echo "⏳ Waiting for Redis..."
    maxcounter=30
    counter=1
    while [ $counter -le $maxcounter ]; do
        if php -r "
            try {
                \$redis = new Redis();
                \$redis->connect('${REDIS_HOST}', ${REDIS_PORT});
                if ('${REDIS_PASSWORD}' !== '') {
                    \$redis->auth('${REDIS_PASSWORD}');
                }
                \$redis->ping();
                exit(0);
            } catch (Exception \$e) {
                exit(1);
            }
        " 2>/dev/null; then
            echo "✅ Redis is ready!"
            break
        fi
        echo "Redis is unavailable - sleeping (attempt $counter/$maxcounter)"
        sleep 2
        counter=$((counter + 1))
    done

    if [ $counter -gt $maxcounter ]; then
        echo "⚠️ Redis connection timeout - continuing anyway"
    fi
}

# Services abwarten
wait_for_db
wait_for_redis

# Application Key generieren falls nicht vorhanden
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    echo "⚙️ Generating application key..."
    php artisan key:generate --force
    export APP_KEY=$(php artisan key:generate --show)
fi

# Migrations ausführen
echo "🔄 Running migrations..."
php artisan migrate --force

# Cache optimieren
echo "🎯 Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Storage Link erstellen
echo "🔗 Creating storage link..."
php artisan storage:link 2>/dev/null || true

# Permissions sicherstellen
echo "🔒 Setting permissions..."
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Queue Restart (falls Queue Worker läuft)
php artisan queue:restart 2>/dev/null || true

echo "✅ Application setup complete!"

# Original command ausführen
exec "$@"