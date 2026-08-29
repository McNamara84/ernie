#!/bin/sh

set -eu

SOURCE_WORKSPACE=/var/www/html
TEST_WORKSPACE=/var/www/pest-workspace

if [ "$TEST_WORKSPACE" != /var/www/pest-workspace ]; then
    echo "Unexpected Pest workspace: $TEST_WORKSPACE" >&2
    exit 1
fi

# The source checkout is a Windows/macOS bind mount in Docker Desktop. Reading
# hundreds of PHP files from it in every ParaTest worker serializes filesystem
# I/O. Copy the current checkout once to a Linux-native named volume instead.
# The separately mounted vendor directory is retained across preparations.
find "$TEST_WORKSPACE" -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf -- {} +

tar \
    --exclude='./.git' \
    --exclude='./.tmp' \
    --exclude='./.vitest' \
    --exclude='./.vitest-reports' \
    --exclude='./coverage' \
    --exclude='./node_modules' \
    --exclude='./playwright-report' \
    --exclude='./test-results' \
    --exclude='./vendor' \
    --exclude='./storage/framework/cache/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    --exclude='./storage/logs/*' \
    -C "$SOURCE_WORKSPACE" \
    -cf - . | tar -C "$TEST_WORKSPACE" -xf -

mkdir -p \
    "$TEST_WORKSPACE/bootstrap/cache" \
    "$TEST_WORKSPACE/storage/framework/cache/data" \
    "$TEST_WORKSPACE/storage/framework/sessions" \
    "$TEST_WORKSPACE/storage/framework/testing" \
    "$TEST_WORKSPACE/storage/framework/views" \
    "$TEST_WORKSPACE/storage/logs"
