#!/bin/bash
# =============================================================================
# CloudBeaver Initial Setup Script
# =============================================================================
# This script creates the initial database connection configuration
# for CloudBeaver when the container starts for the first time.
# =============================================================================

set -e

WORKSPACE="/opt/cloudbeaver/workspace"
GLOBAL_CONFIG_DIR="$WORKSPACE/GlobalConfiguration/.dbeaver"
DATA_SOURCES_FILE="$GLOBAL_CONFIG_DIR/data-sources.json"

# Wait for CloudBeaver to initialize the workspace structure
sleep 5

# Create the GlobalConfiguration directory if it doesn't exist
mkdir -p "$GLOBAL_CONFIG_DIR"

# Only create the data-sources.json if it doesn't exist or is empty
if [ ! -s "$DATA_SOURCES_FILE" ] || [ "$(cat $DATA_SOURCES_FILE 2>/dev/null)" = '{"folders":{},"connections":{}}' ]; then
    echo "Creating initial database connection configuration..."
    
    # Use environment variables if available, otherwise use defaults
    DB_USER="${DB_USER:-ernie}"
    DB_PASS="${DB_PASS:-secret}"
    DB_NAME="${DB_NAME:-ernie}"
    
    cat > "$DATA_SOURCES_FILE" << EOF
{
  "folders": {},
  "connections": {
    "mysql_ernie": {
      "provider": "mysql",
      "driver": "mysql8",
      "name": "ERNIE Database",
      "save-password": true,
      "configuration": {
        "host": "db",
        "port": "3306",
        "database": "$DB_NAME",
        "user": "$DB_USER",
        "password": "$DB_PASS",
        "url": "jdbc:mysql://db:3306/$DB_NAME?allowPublicKeyRetrieval=true&useSSL=false",
        "type": "dev"
      }
    }
  }
}
EOF
    
    echo "Database connection configuration created successfully!"
else
    echo "Database connection configuration already exists, skipping..."
fi
