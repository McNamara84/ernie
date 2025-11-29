# =============================================================================
# Generate Self-Signed SSL Certificates for Local Development (Windows)
# =============================================================================
# This script generates SSL certificates for localhost used by Traefik
# Run this script once before starting the Docker development environment
# Requires: OpenSSL (available via Git Bash, WSL, or standalone installation)
# =============================================================================

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$CertDir = Join-Path $ScriptDir "traefik\certs"
$ConfigFile = Join-Path $CertDir "openssl.cnf"

Write-Host "===================================" -ForegroundColor Cyan
Write-Host "Generating SSL Certificates" -ForegroundColor Cyan
Write-Host "===================================" -ForegroundColor Cyan

# Create directory if it doesn't exist
if (-not (Test-Path $CertDir)) {
    New-Item -ItemType Directory -Path $CertDir -Force | Out-Null
}

# Check if shared OpenSSL config exists
if (-not (Test-Path $ConfigFile)) {
    Write-Host "ERROR: OpenSSL config not found at $ConfigFile" -ForegroundColor Red
    Write-Host "Please ensure openssl.cnf exists in docker/traefik/certs/" -ForegroundColor Yellow
    exit 1
}

$KeyFile = Join-Path $CertDir "localhost.key"
$CertFile = Join-Path $CertDir "localhost.crt"

# Check if openssl is available
$opensslPath = $null
$possiblePaths = @(
    "openssl",
    "C:\Program Files\Git\usr\bin\openssl.exe",
    "C:\Program Files\OpenSSL-Win64\bin\openssl.exe",
    "C:\Program Files (x86)\OpenSSL-Win32\bin\openssl.exe"
)

foreach ($path in $possiblePaths) {
    try {
        $result = & $path version 2>&1
        if ($LASTEXITCODE -eq 0) {
            $opensslPath = $path
            break
        }
    } catch {
        continue
    }
}

if (-not $opensslPath) {
    Write-Host "ERROR: OpenSSL not found!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please install OpenSSL using one of these methods:" -ForegroundColor Yellow
    Write-Host "  1. Install Git for Windows (includes OpenSSL)" -ForegroundColor Yellow
    Write-Host "  2. Download from: https://slproweb.com/products/Win32OpenSSL.html" -ForegroundColor Yellow
    Write-Host "  3. Use WSL: wsl ./docker/generate-certs.sh" -ForegroundColor Yellow
    exit 1
}

Write-Host "Using OpenSSL: $opensslPath" -ForegroundColor Gray
Write-Host "Using config: $ConfigFile" -ForegroundColor Gray

# Generate certificate using shared config
try {
    & $opensslPath req -x509 `
        -newkey rsa:4096 `
        -keyout $KeyFile `
        -out $CertFile `
        -days 365 `
        -nodes `
        -config $ConfigFile

    if ($LASTEXITCODE -ne 0) {
        throw "OpenSSL command failed"
    }

    Write-Host ""
    Write-Host "Certificates generated successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Certificate files:" -ForegroundColor Cyan
    Write-Host "  - $CertFile"
    Write-Host "  - $KeyFile"
    Write-Host ""
    Write-Host "To trust the certificate in Windows:" -ForegroundColor Yellow
    Write-Host "  1. Double-click localhost.crt" -ForegroundColor White
    Write-Host "  2. Click 'Install Certificate'" -ForegroundColor White
    Write-Host "  3. Select 'Local Machine'" -ForegroundColor White
    Write-Host "  4. Select 'Place all certificates in the following store'" -ForegroundColor White
    Write-Host "  5. Browse and select 'Trusted Root Certification Authorities'" -ForegroundColor White
    Write-Host "  6. Click Finish" -ForegroundColor White
    Write-Host ""
    Write-Host "Or run this PowerShell command as Administrator:" -ForegroundColor Yellow
    Write-Host "  Import-Certificate -FilePath `"$CertFile`" -CertStoreLocation Cert:\LocalMachine\Root" -ForegroundColor White

} catch {
    Write-Host "ERROR: Failed to generate certificates" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
