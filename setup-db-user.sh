#!/bin/bash
# Database Setup Script for ERNIE
# Run this after the containers are up and running

echo "=== ERNIE Database Setup ==="

# Check if containers are running
if ! docker ps | grep -q "dockerized-laravel-db"; then
    echo "Error: Database container is not running!"
    exit 1
fi

if ! docker ps | grep -q "dockerized-laravel-app"; then
    echo "Error: App container is not running!"
    exit 1
fi

echo "✓ Containers are running"

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until docker exec dockerized-laravel-db mysqladmin ping -h"localhost" -u root -p"GFZdatabase2025!" --silent 2>/dev/null; do
    echo "  Waiting for database..."
    sleep 2
done

echo "✓ MySQL is ready"

# Run migrations
echo "Running Laravel migrations..."
if docker exec dockerized-laravel-app php artisan migrate --force --no-interaction; then
    echo "✓ Migrations completed successfully"
else
    echo "✗ Migration failed"
    exit 1
fi

# Optional: Create Laravel user (uncomment if you want a dedicated user later)
# echo "Creating dedicated Laravel user..."
# docker exec dockerized-laravel-db mysql -u root -p"GFZdatabase2025!" -e "
# CREATE USER IF NOT EXISTS 'laravel'@'%' IDENTIFIED WITH mysql_native_password BY 'GFZdatabase2025!';
# GRANT ALL PRIVILEGES ON ernie.* TO 'laravel'@'%';
# FLUSH PRIVILEGES;
# "
# echo "✓ Laravel user created"

echo ""
echo "=== Setup Complete! ==="
echo "Your ERNIE application should now be fully functional at:"
echo "https://env.rz-vm182.gfz.de/ernie/"