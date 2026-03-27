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

# The Laravel Vite plugin creates/manages the public/hot file on startup,
# but Docker Desktop on Windows sometimes fails to sync bind-mounted file
# creation back to the host. As a safety net, create the hot file before
# starting Vite. The plugin will overwrite it with the same content on boot.
echo "Ensuring public/hot file exists..."
mkdir -p public
echo "${VITE_DEV_SERVER_URL:-http://localhost:5173}" > public/hot

# Start Vite dev server
echo "Starting Vite development server..."
exec npm run dev -- --host 0.0.0.0
