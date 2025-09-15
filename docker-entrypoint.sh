#!/bin/sh
set -e

echo "🚀 Starting Laravel Application..."

# Warten auf MariaDB
echo "⏳ Waiting for MariaDB..."
until php artisan db:monitor --database=mysql > /dev/null 2>&1; do
    echo "MariaDB is unavailable - sleeping"
    sleep 3
done
echo "✅ MariaDB is ready!"

# Warten auf Redis
echo "⏳ Waiting for Redis..."
until php artisan redis:monitor > /dev/null 2>&1; do
    echo "Redis is unavailable - sleeping"
    sleep 3
done
echo "✅ Redis is ready!"

# Application Key generieren falls nicht vorhanden
if [ -z "$APP_KEY" ]; then
    echo "⚙️ Generating application key..."
    php artisan key:generate --force
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
php artisan storage:link || true

# Permissions sicherstellen
echo "🔒 Setting permissions..."
chmod -R 775 storage bootstrap/cache

# Queue Restart (falls Queue Worker läuft)
php artisan queue:restart || true

echo "✅ Application setup complete!"

# Original command ausführen
exec "$@"