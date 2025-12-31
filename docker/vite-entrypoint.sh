#!/bin/sh
set -e

cd /var/www/html

# Ensure PHP is in PATH (required for Wayfinder plugin)
export PATH="/usr/local/bin:$PATH"

# Install npm dependencies if needed
if [ ! -d "node_modules" ] || [ -z "$(ls -A node_modules 2>/dev/null)" ]; then
    echo "Installing npm dependencies..."
    npm install
fi

# Create the hot file with the correct Vite dev server URL
# This tells Laravel where to load Vite assets from
HOT_FILE="/var/www/html/public/hot"
echo "Creating hot file at $HOT_FILE..."
HOT_URL="${VITE_DEV_SERVER_URL:-${APP_URL:-https://ernie.localhost:3333}}"
echo "$HOT_URL" > "$HOT_FILE"

# Start Vite dev server
echo "Starting Vite development server..."
exec npm run dev -- --host 0.0.0.0
