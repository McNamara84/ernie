#!/bin/bash
# Shared by the development and Stage/Production entrypoints.

run_as_www_data() {
    local config_directory="${STORAGE_PATH:-${APP_PATH:-/var/www/html}/storage}/framework/.config"
    if [ "$(id -u)" = "0" ]; then
        setpriv --reuid=www-data --regid=www-data --init-groups -- \
            env XDG_CONFIG_HOME="$config_directory" "$@"
    else
        env XDG_CONFIG_HOME="$config_directory" "$@"
    fi
}

exec_as_www_data() {
    local config_directory="${STORAGE_PATH:-${APP_PATH:-/var/www/html}/storage}/framework/.config"
    if [ "$(id -u)" = "0" ]; then
        exec setpriv --reuid=www-data --regid=www-data --init-groups -- \
            env XDG_CONFIG_HOME="$config_directory" "$@"
    else
        exec env XDG_CONFIG_HOME="$config_directory" "$@"
    fi
}

prepare_laravel_permissions() {
    # Validate all managed roots and their parents before mkdir/chown/chmod.
    # A writable runtime directory must not redirect root into application code.
    local directory
    for directory in "$APP_PATH" "$APP_PATH/bootstrap" "$APP_PATH/bootstrap/cache" \
        "$STORAGE_PATH" "$STORAGE_PATH/framework" "$STORAGE_PATH/framework/cache" \
        "$STORAGE_PATH/framework/sessions" "$STORAGE_PATH/framework/views" \
        "$STORAGE_PATH/app" "$STORAGE_PATH/app/private" "$STORAGE_PATH/app/public" "$STORAGE_PATH/logs"; do
        if [ -L "$directory" ]; then
            echo "ERROR: Runtime directory must not be a symbolic link: $directory" >&2
            return 1
        fi
    done

    mkdir -p "$STORAGE_PATH/framework/cache" "$STORAGE_PATH/framework/sessions" \
        "$STORAGE_PATH/framework/views" "$STORAGE_PATH/app/private" "$STORAGE_PATH/app/public" \
        "$STORAGE_PATH/logs" "$APP_PATH/bootstrap/cache"

    # Initialize the data roots without walking uploaded files on every start.
    chown --no-dereference www-data:www-data "$STORAGE_PATH" "$STORAGE_PATH/app" \
        "$STORAGE_PATH/app/private" "$STORAGE_PATH/app/public"
    chmod --no-dereference u+rwX,g+rwX "$STORAGE_PATH" "$STORAGE_PATH/app" \
        "$STORAGE_PATH/app/private" "$STORAGE_PATH/app/public"

    # Repair only Laravel's runtime files, including logs from older root calls.
    chown -RP --no-dereference www-data:www-data "$STORAGE_PATH/framework" "$STORAGE_PATH/logs" "$APP_PATH/bootstrap/cache"
    # Preserve the setgid bit on an existing log directory during restarts.
    chmod -RP --no-dereference u+rwX,g+rwX "$STORAGE_PATH/framework" "$STORAGE_PATH/logs" "$APP_PATH/bootstrap/cache"
    # Even an accidental root Artisan call inherits the web worker's group.
    # The single/daily Monolog channels explicitly create group-writable files.
    chmod --no-dereference 2775 "$STORAGE_PATH/logs"
}

prepare_storage_link() (
    if [ "${SKIP_STORAGE_LINK:-0}" = "1" ]; then
        return
    fi

    # Keep public/ owned by root; creating this link does not need to boot Laravel.
    local public_directory="$APP_PATH/public"
    if [ -L "$public_directory" ]; then
        echo "ERROR: Public directory must not be a symbolic link: $public_directory" >&2
        return 1
    fi
    local link="$public_directory/storage"
    if [ -e "$link" ] && [ ! -L "$link" ]; then
        echo "ERROR: $link exists and is not a symbolic link" >&2
        return 1
    fi
    # App and queue containers can initialize the same public/ concurrently.
    # Reserve a unique path on the same filesystem, then publish it atomically.
    local temporary_directory
    temporary_directory=$(mktemp -d "$public_directory/.storage-link.XXXXXXXXXX") || return
    trap 'rm -f -- "$temporary_directory/storage"; rmdir -- "$temporary_directory"' EXIT

    ln -s -- "$STORAGE_PATH/app/public" "$temporary_directory/storage" || return
    # -T replaces the link itself and refuses to move into an actual directory.
    mv -Tf -- "$temporary_directory/storage" "$link"
)

require_application_key() {
    if [ -z "${APP_KEY:-}" ]; then
        echo 'ERROR: Set one shared APP_KEY for all app, queue and scheduler containers before deployment.' >&2
        return 1
    fi
}

reject_environment_symlink() {
    if [ -L "$1" ]; then
        echo "ERROR: Environment file must not be a symbolic link: $1" >&2
        return 1
    fi
}

initialize_development_environment() (
    local environment_file="$APP_PATH/.env"
    local environment_lock
    reject_environment_symlink "$environment_file" || return
    # Create an absent shared file without truncating another initializer's work.
    # nofollow also rejects a link inserted after the explicit validation.
    if [ ! -f "$environment_file" ]; then
        dd if=/dev/null of="$environment_file" oflag=append,nofollow conv=notrunc status=none || return
    fi
    # A read-only descriptor allows locking without handing a writable .env
    # descriptor to Artisan when it runs as www-data.
    exec {environment_lock}<"$environment_file" || return
    flock --exclusive "$environment_lock" || return
    reject_environment_symlink "$environment_file" || return

    if [ ! -s "$environment_file" ]; then
        if [ -f "$APP_PATH/.env.docker" ]; then
            dd if="$APP_PATH/.env.docker" of="$environment_file" oflag=nofollow status=none || return
        elif [ -f "$APP_PATH/.env.example" ]; then
            dd if="$APP_PATH/.env.example" of="$environment_file" oflag=nofollow status=none || return
        else
            echo 'ERROR: No development environment template is available.' >&2
            return 1
        fi
    fi

    if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:.' "$environment_file"; then
        run_as_www_data php artisan config:clear --no-interaction || return
        generate_application_key --force --no-interaction
    fi
)

generate_application_key() (
    local environment_file="$APP_PATH/.env"
    local key temporary_file
    reject_environment_symlink "$environment_file" || return
    if ! grep -q '^APP_KEY=' "$environment_file"; then
        echo 'ERROR: .env must contain an APP_KEY assignment' >&2
        return 1
    fi

    # Laravel boots as the web user, but never needs write access to .env.
    key=$(run_as_www_data php artisan key:generate --show --no-ansi "$@") || return
    if [[ ! "$key" =~ ^base64:[A-Za-z0-9+/]+={0,2}$ ]]; then
        echo 'ERROR: key:generate did not return a valid base64 key' >&2
        return 1
    fi

    reject_environment_symlink "$environment_file" || return
    # Keep credentials in a root-only temporary file; scope cleanup to this call.
    # Git and Docker also exclude .env-key.* if an abrupt kill prevents cleanup.
    temporary_file=$(mktemp "$APP_PATH/.env-key.XXXXXXXXXX") || return
    trap 'rm -f -- "$temporary_file"' EXIT
    # Supply the key on stdin rather than exposing it in process arguments.
    # Preserve CRLF and all other configuration, including quoted credentials.
    sed -f - "$environment_file" > "$temporary_file" <<SED || return
s|^APP_KEY=[^\r]*|APP_KEY=$key|
SED
    # Write through the original file to preserve ownership/mode and support
    # an individually bind-mounted .env. Only the setup process performs this.
    reject_environment_symlink "$environment_file" || return
    dd if="$temporary_file" iflag=nofollow of="$environment_file" oflag=nofollow status=none
)

exec_laravel_runtime() {
    # FPM's master retains root for its pool setup; pool workers use www-data.
    # This also covers scheduler shell commands and future PHP worker services.
    if [ "${1:-}" = "php-fpm" ]; then
        exec "$@"
    fi
    exec_as_www_data "$@"
}
