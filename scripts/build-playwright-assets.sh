#!/bin/sh

set -eu

# prepare-pest-workspace.sh refreshes this Linux-native copy first. Reuse the
# verified Docker dependencies without reading application sources over the
# Docker Desktop bind mount for every build plugin.
cd /var/www/pest-workspace
ln -s /var/www/html/node_modules node_modules
export WAYFINDER_COMMAND='php -d memory_limit=2G artisan ernie:wayfinder-generate'
npm run build
