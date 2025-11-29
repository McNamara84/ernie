#!/bin/bash
# =============================================================================
# Generate Self-Signed SSL Certificates for Local Development
# =============================================================================
# This script generates SSL certificates for localhost used by Traefik
# Run this script once before starting the Docker development environment
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERT_DIR="$SCRIPT_DIR/traefik/certs"
CONFIG_FILE="$CERT_DIR/openssl.cnf"

echo "==================================="
echo "Generating SSL Certificates"
echo "==================================="

mkdir -p "$CERT_DIR"

# Check if shared OpenSSL config exists
if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: OpenSSL config not found at $CONFIG_FILE"
    echo "Please ensure openssl.cnf exists in docker/traefik/certs/"
    exit 1
fi

# Generate private key and self-signed certificate using shared config
openssl req -x509 \
    -newkey rsa:4096 \
    -keyout "$CERT_DIR/localhost.key" \
    -out "$CERT_DIR/localhost.crt" \
    -days 365 \
    -nodes \
    -config "$CONFIG_FILE"

echo "✓ Certificates generated successfully!"
echo ""
echo "Certificate files:"
echo "  - $CERT_DIR/localhost.crt"
echo "  - $CERT_DIR/localhost.key"
echo ""
echo "To trust the certificate on your system:"
echo ""
echo "Windows:"
echo "  1. Double-click localhost.crt"
echo "  2. Install Certificate → Local Machine → Trusted Root Certification Authorities"
echo ""
echo "macOS:"
echo "  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain $CERT_DIR/localhost.crt"
echo ""
echo "Linux (Ubuntu/Debian):"
echo "  sudo cp $CERT_DIR/localhost.crt /usr/local/share/ca-certificates/"
echo "  sudo update-ca-certificates"
