#!/usr/bin/env bash

set -euo pipefail

environment_file="${1:-.env.production}"

if [[ ! -r "$environment_file" ]]; then
    echo "Production environment template is not readable: $environment_file" >&2
    exit 1
fi

invalid_keys=()

while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"

    if [[ -z "$line" || "$line" == \#* || "$line" != *=* ]]; then
        continue
    fi

    key="${line%%=*}"
    value="${line#*=}"
    credential_key=false

    if [[ "$key" == "APP_KEY" ||
        "$key" == "AWS_ACCESS_KEY_ID" ||
        "$key" =~ _(PASSWORD|SECRET|TOKEN|API_KEY|ACCESS_KEY|PRIVATE_KEY|ENCRYPTION_KEY|SIGNING_KEY|AUTH|CREDENTIAL|CREDENTIALS)$ ||
        ( "$key" == *_USERNAME && "$key" != "DB_USERNAME" ) ]]; then
        credential_key=true
    fi

    if [[ "$credential_key" == true && -n "$value" && "$value" != "null" ]]; then
        invalid_keys+=("$key")
    fi
done < "$environment_file"

if (( ${#invalid_keys[@]} > 0 )); then
    printf 'Credential-like values in %s must be empty or use the null sentinel:\n' "$environment_file" >&2
    printf '  - %s\n' "${invalid_keys[@]}" >&2
    exit 1
fi

echo "Validated sanitized production environment template: $environment_file"
