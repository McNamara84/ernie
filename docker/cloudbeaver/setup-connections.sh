#!/bin/bash
# =============================================================================
# CloudBeaver Entrypoint Script
# =============================================================================
# This script creates the initial database connection configuration
# for CloudBeaver when the container starts for the first time,
# then starts CloudBeaver normally.
# =============================================================================

WORKSPACE="/opt/cloudbeaver/workspace"
GLOBAL_CONFIG_DIR="$WORKSPACE/GlobalConfiguration/.dbeaver"
DATA_SOURCES_FILE="$GLOBAL_CONFIG_DIR/data-sources.json"

# Function to escape strings for JSON (handles quotes, backslashes, newlines)
json_escape() {
    local str="$1"
    # Escape backslashes first, then quotes, then control characters
    str="${str//\\/\\\\}"
    str="${str//\"/\\\"}"
    str="${str//$'\n'/\\n}"
    str="${str//$'\r'/\\r}"
    str="${str//$'\t'/\\t}"
    printf '%s' "$str"
}

# Function to setup database connection
setup_connection() {
    # Create the GlobalConfiguration directory if it doesn't exist
    mkdir -p "$GLOBAL_CONFIG_DIR"

    # Only create the data-sources.json if it doesn't exist or is empty/default
    if [ ! -s "$DATA_SOURCES_FILE" ] || grep -q '"connections":{}' "$DATA_SOURCES_FILE" 2>/dev/null; then
        echo "[ERNIE Setup] Creating initial database connection configuration..."
        
        # Use environment variables (from docker-compose) with defaults
        # Apply JSON escaping to prevent injection issues with special characters
        DB_USER=$(json_escape "${CB_DB_USER:-ernie}")
        DB_PASS=$(json_escape "${CB_DB_PASSWORD:-secret}")
        DB_NAME=$(json_escape "${CB_DB_NAME:-ernie}")
        DB_HOST=$(json_escape "${CB_DB_HOST:-db}")
        
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
        "host": "$DB_HOST",
        "port": "3306",
        "database": "$DB_NAME",
        "user": "$DB_USER",
        "password": "$DB_PASS",
        "url": "jdbc:mysql://$DB_HOST:3306/$DB_NAME?allowPublicKeyRetrieval=true&useSSL=false",
        "type": "dev"
      }
    }
  }
}
EOF
        
        echo "[ERNIE Setup] Database connection configuration created successfully!"
    else
        echo "[ERNIE Setup] Database connection already configured, skipping..."
    fi
}

# Run setup
setup_connection

# Start CloudBeaver (execute the original entrypoint)
exec /opt/cloudbeaver/launch-product.sh
