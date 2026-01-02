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

# Note: The Laravel Vite plugin automatically creates/manages the public/hot file.
# It writes the Vite dev server URL (from VITE_DEV_SERVER_URL env var) on startup
# and removes it on shutdown. No manual hot file creation needed here.

# Start Vite dev server
echo "Starting Vite development server..."
exec npm run dev -- --host 0.0.0.0
