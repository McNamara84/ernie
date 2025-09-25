#!/bin/bash
# MySQL Database Setup Script for ERNIE
# Run this after the containers are up and running

echo "Setting up Laravel database user..."

# Wait for MySQL to be ready
until docker exec dockerized-laravel-db mysqladmin ping -h"localhost" -u root -p"${DB_PASSWORD}" --silent; do
    echo "Waiting for database to be ready..."
    sleep 2
done

# Create Laravel user with proper permissions
docker exec dockerized-laravel-db mysql -u root -p"${DB_PASSWORD}" -e "
CREATE USER IF NOT EXISTS 'laravel'@'%' IDENTIFIED WITH mysql_native_password BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_DATABASE}.* TO 'laravel'@'%';
FLUSH PRIVILEGES;
"

echo "Laravel database user created successfully!"
echo "You can now update your stack.env to use:"
echo "DB_USERNAME=laravel"
echo "And redeploy the stack."