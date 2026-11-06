<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @param list<string> $command */
function laravelRuntimeProcess(array $command, string $directory, bool $root = true): Process
{
    $identity = new Process(['id', '-u']);
    $identity->mustRun();
    $prefix = $root && trim($identity->getOutput()) !== '0' ? ['sudo', '-n', '--'] : [];

    return new Process([...$prefix, ...$command], $directory, timeout: 30);
}

beforeEach(function (): void {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Linux ownership and privilege switching require a Linux test runner.');
    }
    $probe = laravelRuntimeProcess(['id', '-u'], sys_get_temp_dir());
    $probe->run();
    if (! $probe->isSuccessful() || trim($probe->getOutput()) !== '0') {
        $this->markTestSkipped('Run in the repository Docker wrapper or a CI runner with passwordless sudo.');
    }

    $this->runtimeDirectory = sys_get_temp_dir().'/ernie-runtime-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($this->runtimeDirectory, 0755);
    foreach (['bin', 'public', 'node_modules', 'vendor', 'libraries'] as $directory) {
        $files->makeDirectory($this->runtimeDirectory.'/'.$directory, 0755);
    }
    $files->copy(base_path('docker/laravel-runtime.sh'), $this->runtimeDirectory.'/bin/laravel-runtime.sh');
    $files->copy(base_path('docker-entrypoint.sh'), $this->runtimeDirectory.'/bin/production-entrypoint.sh');
    $files->copy(base_path('docker/dev-entrypoint.sh'), $this->runtimeDirectory.'/bin/development-entrypoint.sh');
    $files->copy(base_path('tests/fixtures/runtime/log-writer.php'), $this->runtimeDirectory.'/log-writer.php');
    $files->copy(base_path('tests/fixtures/runtime/key-consumer.php'), $this->runtimeDirectory.'/key-consumer.php');
    $files->copyDirectory(base_path('vendor/monolog/monolog/src/Monolog'), $this->runtimeDirectory.'/libraries/monolog');
    $files->copyDirectory(base_path('vendor/psr/log/src'), $this->runtimeDirectory.'/libraries/psr-log');
    foreach ([
        'vlucas/phpdotenv/src' => 'dotenv',
        'phpoption/phpoption/src/PhpOption' => 'phpoption',
        'graham-campbell/result-type/src' => 'result-type',
        'laravel/framework/src/Illuminate/Encryption' => 'encryption',
        'laravel/framework/src/Illuminate/Contracts/Encryption' => 'encryption-contracts',
    ] as $source => $destination) {
        $files->copyDirectory(base_path('vendor/'.$source), $this->runtimeDirectory.'/libraries/'.$destination);
    }
    $files->makeDirectory($this->runtimeDirectory.'/libraries/support');
    $files->copy(base_path('vendor/laravel/framework/src/Illuminate/Support/Env.php'), $this->runtimeDirectory.'/libraries/support/Env.php');
    foreach (['artisan', '.env.example', 'vendor/autoload.php', 'node_modules/.fixture'] as $file) {
        $files->put($this->runtimeDirectory.'/'.$file, 'fixture');
    }
});

afterEach(function (): void {
    $directory = $this->runtimeDirectory ?? null;
    if (is_string($directory) && preg_match('~^'.preg_quote(sys_get_temp_dir(), '~').'/ernie-runtime-[a-f0-9]{16}$~', $directory)) {
        laravelRuntimeProcess(['rm', '-rf', '--', $directory], sys_get_temp_dir())->mustRun();
    }
});

it('lets web workers append to new logs even when root writes first', function (string $channel): void {
    $directory = $this->runtimeDirectory;
    laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-c', 'source bin/laravel-runtime.sh; prepare_laravel_permissions'], $directory)->mustRun();

    // Two distinct new files exercise repeated daily-file creation, not just a
    // repaired file that was already open before the deployment.
    foreach (['first-day', 'next-day'] as $day) {
        $path = $directory.'/storage/logs/'.$day.'.log';
        $writer = [PHP_BINARY, '-d', 'memory_limit=2G', $directory.'/log-writer.php',
            $channel, $path, (string) config('logging.channels.'.$channel.'.permission'), 'root-first'];
        laravelRuntimeProcess(['sh', '-c', 'umask 077; exec "$@"', 'root-writer', ...$writer], $directory)->mustRun();
        $writer[array_key_last($writer)] = 'web-second';
        laravelRuntimeProcess(['setpriv', '--reuid=www-data', '--regid=www-data', '--init-groups', '--', ...$writer], $directory)->mustRun();

        $actualPath = $channel === 'daily' ? substr($path, 0, -4).'-'.date('Y-m-d').'.log' : $path;
        $group = laravelRuntimeProcess(['id', '-g', 'www-data'], $directory);
        $group->mustRun();
        clearstatcache(true, $actualPath);
        expect(file_get_contents($actualPath))->toContain('root-first', 'web-second')
            ->and(filegroup($actualPath))->toBe((int) trim($group->getOutput()))
            ->and(fileperms($actualPath) & 0777)->toBe(0664);
    }
})->with(['single', 'daily']);

it('repairs an existing root-owned log and preserves inheritance after another startup', function (): void {
    $directory = $this->runtimeDirectory;
    $script = <<<'BASH'
set -e
source bin/laravel-runtime.sh
mkdir -p "$STORAGE_PATH/logs"
printf 'existing history\n' > "$STORAGE_PATH/logs/laravel.log"
chmod 644 "$STORAGE_PATH/logs/laravel.log"
prepare_laravel_permissions
prepare_laravel_permissions
run_as_www_data sh -c 'echo appended >> "$STORAGE_PATH/logs/laravel.log"'
test "$(stat -c %U "$STORAGE_PATH/logs/laravel.log")" = www-data
test -g "$STORAGE_PATH/logs"
BASH;
    laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-c', $script], $directory)->mustRun();
    expect(file_get_contents($directory.'/storage/logs/laravel.log'))->toBe("existing history\nappended\n");
});

it('repairs runtime files and initializes disk roots without changing uploaded data', function (): void {
    $directory = $this->runtimeDirectory;
    $script = <<<'BASH'
set -eu
source bin/laravel-runtime.sh
mkdir -p "$STORAGE_PATH/app/private/archive/nested" "$STORAGE_PATH/app/public/archive/nested" \
    "$STORAGE_PATH/framework/cache/data" "$STORAGE_PATH/logs" "$APP_PATH/bootstrap/cache"
uploads=()
for disk in private public; do
    archive="$STORAGE_PATH/app/$disk/archive"
    printf 'existing upload\n' > "$archive/nested/data.txt"
    chmod 700 "$archive" "$archive/nested"
    chmod 400 "$archive/nested/data.txt"
    uploads+=("$archive" "$archive/nested" "$archive/nested/data.txt")
done
stat -c '%u:%g:%a:%n' "${uploads[@]}" > uploads-before.txt
for file in "$STORAGE_PATH/framework/cache/data/cached.php" "$STORAGE_PATH/logs/laravel.log" "$APP_PATH/bootstrap/cache/config.php"; do
    printf 'existing runtime data\n' > "$file"
    chmod 400 "$file"
done
prepare_laravel_permissions
prepare_laravel_permissions
stat -c '%u:%g:%a:%n' "${uploads[@]}" > uploads-after.txt
run_as_www_data sh -eu -c '
    for file in "$STORAGE_PATH/framework/cache/data/cached.php" "$STORAGE_PATH/logs/laravel.log" "$APP_PATH/bootstrap/cache/config.php"; do
        echo appended >> "$file"
        cat "$file"
    done
    for directory in "$STORAGE_PATH" "$STORAGE_PATH/app" "$STORAGE_PATH/app/private" "$STORAGE_PATH/app/public"; do
        echo new-data > "$directory/new-file"
    done
'
BASH;
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-c', $script], $directory)->mustRun();

    expect(file_get_contents($directory.'/uploads-after.txt'))->toBe(file_get_contents($directory.'/uploads-before.txt'));
    // Read restricted runtime files as www-data, not as the unrelated CI runner.
    expect($process->getOutput())->toBe(str_repeat("existing runtime data\nappended\n", 3));
});

it('rejects symlinked runtime directories before creating or repairing files', function (string $path, bool $dangling): void {
    $directory = $this->runtimeDirectory;
    $script = <<<'BASH'
set -eu
mkdir -p public/protected
printf 'protected data\n' > public/protected/file
chmod 700 public/protected
chmod 400 public/protected/file
mkdir -p "$(dirname "$LINK_PATH")"
ln -s "$LINK_TARGET" "$LINK_PATH"
BASH;
    laravelRuntimeProcess(['env', 'LINK_PATH='.$path,
        'LINK_TARGET='.$directory.'/public/'.($dangling ? 'missing' : 'protected'),
        'bash', '-c', $script], $directory)->mustRun();
    $snapshot = ['find', '-P', $directory.'/public', '-printf', '%P:%U:%G:%m:%y\n'];
    $before = laravelRuntimeProcess($snapshot, $directory)->mustRun()->getOutput();

    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-ec', 'source bin/laravel-runtime.sh; prepare_laravel_permissions'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Runtime directory must not be a symbolic link: '.$directory.'/'.$path)
        ->and(laravelRuntimeProcess($snapshot, $directory)->mustRun()->getOutput())->toBe($before);
})->with([
    'storage', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions',
    'storage/framework/views', 'storage/logs', 'storage/app', 'storage/app/private',
    'storage/app/public', 'bootstrap', 'bootstrap/cache',
])->with(['existing target' => false, 'dangling link' => true]);

it('does not follow nested runtime symlinks during permission repair', function (): void {
    $directory = $this->runtimeDirectory;
    $script = <<<'BASH'
set -eu
source bin/laravel-runtime.sh
prepare_laravel_permissions
mkdir -p public/protected
printf 'protected data\n' > public/protected/file
chmod 700 public/protected
chmod 400 public/protected/file
run_as_www_data ln -s "$APP_PATH/public/protected" "$STORAGE_PATH/logs/external-directory"
run_as_www_data ln -s "$APP_PATH/public/protected/file" "$STORAGE_PATH/framework/cache/external-file"
stat -c '%u:%g:%a' public/protected public/protected/file > before.txt
prepare_laravel_permissions
prepare_laravel_permissions
stat -c '%u:%g:%a' public/protected public/protected/file > after.txt
test -L "$STORAGE_PATH/logs/external-directory"
test -L "$STORAGE_PATH/framework/cache/external-file"
BASH;
    laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-c', $script], $directory)->mustRun();
    expect(file_get_contents($directory.'/after.txt'))->toBe(file_get_contents($directory.'/before.txt'));
});

it('installs development dependencies and runs Composer hooks as the web user', function (bool $partial, int $exitCode): void {
    $directory = $this->runtimeDirectory;
    unlink($directory.'/vendor/autoload.php');
    rmdir($directory.'/vendor');
    if ($partial) {
        laravelRuntimeProcess(['sh', '-c', 'mkdir -p vendor/package; echo partial > vendor/package/install-state'], $directory)->mustRun();
    }
    $environment = 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n";
    file_put_contents($directory.'/.env.example', $environment);
    laravelRuntimeProcess(['install', '-m', '0644', $directory.'/.env.example', $directory.'/.env'], $directory)->mustRun();
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\nexit 0\n");
    chmod($directory.'/bin/sed', 0755);
    file_put_contents($directory.'/bin/composer', <<<'BASH'
#!/bin/sh
set -eu
printf '%s composer %s\n' "$(id -un)" "$*" >> "$APP_PATH/storage/logs/install.log"
test "$(id -un)" = www-data
test -w "$APP_PATH/vendor"
test ! -w "$APP_PATH"
test ! -w "$APP_PATH/.env"
test "$COMPOSER_HOME" = "$APP_PATH/storage/framework/.config/composer"
mkdir -p "$COMPOSER_HOME/cache"
echo configuration > "$COMPOSER_HOME/config.json"
if [ "$COMPOSER_TEST_EXIT_CODE" != 0 ]; then exit "$COMPOSER_TEST_EXIT_CODE"; fi
echo autoload > "$APP_PATH/vendor/autoload.php"
if [ -f "$APP_PATH/vendor/package/install-state" ]; then
    echo complete > "$APP_PATH/vendor/package/install-state"
fi
# Exercise the same child-process privilege inheritance as post-autoload-dump.
php artisan package:discover --ansi
BASH);
    chmod($directory.'/bin/composer', 0755);
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
set -eu
printf '%s %s\n' "$(id -un)" "$*" >> "$APP_PATH/storage/logs/install.log"
test "$(id -un)" = www-data
case "$*" in
    *package:discover*) echo discovered > "$APP_PATH/bootstrap/cache/packages.php" ;;
    *tinker*) echo 1 ;;
esac
BASH);
    chmod($directory.'/bin/php', 0755);

    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_KEY=', 'DB_HOST=',
        'COMPOSER_TEST_EXIT_CODE='.(string) $exitCode, 'PATH='.$directory.'/bin:'.getenv('PATH'),
        'bash', 'bin/development-entrypoint.sh', 'php', 'artisan', 'schedule:work'], $directory);
    $process->run();

    $trace = file_get_contents($directory.'/storage/logs/install.log');
    expect($process->getExitCode())->toBe($exitCode)
        ->and($trace)->toContain('www-data composer install --no-interaction --optimize-autoloader')
        ->not->toMatch('/^root /m')
        ->and(file_get_contents($directory.'/.env'))->toBe($environment)
        ->and(fileowner($directory.'/.env'))->toBe(0)
        ->and(fileperms($directory.'/.env') & 0777)->toBe(0644);
    if ($exitCode !== 0) {
        expect($trace)->not->toContain('artisan')
            ->and(is_file($directory.'/vendor/autoload.php'))->toBeFalse();

        return;
    }

    $identity = laravelRuntimeProcess(['id', '-u', 'www-data'], $directory);
    $identity->mustRun();
    $webUserId = (int) trim($identity->getOutput());
    expect($trace)->toContain('www-data artisan package:discover --ansi', 'www-data artisan schedule:work')
        ->and(fileowner($directory.'/vendor/autoload.php'))->toBe($webUserId)
        ->and(fileowner($directory.'/bootstrap/cache/packages.php'))->toBe($webUserId)
        ->and(fileowner($directory.'/storage/framework/.config/composer/config.json'))->toBe($webUserId);
    if ($partial) {
        expect(file_get_contents($directory.'/vendor/package/install-state'))->toBe("complete\n");
    }
})->with([
    'fresh installation' => [false, 0],
    'partial root-owned installation' => [true, 0],
    'failed installation' => [false, 17],
]);

it('runs startup commands as the web user without granting environment write access', function (string $entrypoint, bool $existing): void {
    $directory = $this->runtimeDirectory;
    $key = 'base64:'.base64_encode(random_bytes(32));
    file_put_contents($directory.'/generated-key', $key);
    $newline = $existing ? "\r\n" : "\n";
    $contents = "# Keep configuration intact{$newline}APP_KEY={$newline}DB_PASSWORD=\"fixture # value=unchanged\"";
    file_put_contents($directory.'/.env.example', $contents);
    if ($existing) {
        laravelRuntimeProcess(['install', '-m', '0444', $directory.'/.env.example', $directory.'/.env'], $directory)->mustRun();
    }
    $phpStub = <<<'BASH'
#!/bin/sh
set -e
printf '%s %s\n' "$(id -un)" "$*" >> "$APP_PATH/storage/logs/startup.log"
test "$XDG_CONFIG_HOME" = "$APP_PATH/storage/framework/.config"
case "$*" in
    *key:generate*)
        test -r "$APP_PATH/.env"
        test ! -w "$APP_PATH/.env"
        case "$*" in *--show*) ;; *) exit 1 ;; esac
        cat "$APP_PATH/generated-key"
        ;;
    *tinker*) echo 1 ;;
    *runtime:key-probe*) "$REAL_PHP" -d memory_limit=2G "$APP_PATH/key-consumer.php" fingerprint ;;
esac
BASH;
    file_put_contents($directory.'/bin/php', $phpStub);
    chmod($directory.'/bin/php', 0755);
    // The dev entrypoint's FPM configuration is not under test; never touch the
    // test runner's actual pool configuration.
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\ncase \"\$*\" in *php-fpm.d/www.conf*) exit 0 ;; esac\nexec /usr/bin/sed \"\$@\"\n");
    chmod($directory.'/bin/sed', 0755);

    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_ENV=production', 'APP_KEY=',
        'DB_HOST=', 'ERNIE_RUN_MIGRATIONS=0', 'ERNIE_CONTAINER_ROLE=scheduler', 'REAL_PHP='.PHP_BINARY,
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', 'bin/'.$entrypoint.'-entrypoint.sh',
        'sh', '-c', 'test -r .env && test ! -w .env && php artisan runtime:key-probe && php artisan rights:update-usage-count && exec php artisan schedule:work'], $directory);
    $process->mustRun();

    $trace = file_get_contents($directory.'/storage/logs/startup.log');
    expect($trace)->toContain('www-data artisan config:clear', 'www-data artisan key:generate',
        'www-data artisan rights:update-usage-count', 'www-data artisan schedule:work')
        ->not->toMatch('/^root /m')
        ->and(readlink($directory.'/public/storage'))->toBe($directory.'/storage/app/public')
        ->and(file_get_contents($directory.'/.env'))->toBe(str_replace('APP_KEY=', 'APP_KEY='.$key, $contents))
        ->and(fileowner($directory.'/.env'))->toBe(0)
        ->and(fileperms($directory.'/.env') & 0777)->toBe($existing ? 0444 : 0644)
        ->and($process->getOutput())->not->toContain($key)
        ->and($process->getOutput())->toContain('key-fingerprint:'.hash('sha256', base64_decode(substr($key, 7))))
        ->and(glob($directory.'/.env-key.*'))->toBe([]);
})->with(['development'])->with(['new environment' => false, 'existing environment' => true]);

it('rejects symlinked environment files before development initialization', function (string $state): void {
    $directory = $this->runtimeDirectory;
    $target = $directory.'/host-file';
    $key = 'base64:'.base64_encode(random_bytes(32));
    $contents = match ($state) {
        'empty' => '',
        'without key' => "APP_KEY=\nKEEP=host-data\n",
        'with key' => "APP_KEY={$key}\nKEEP=host-data\n",
        default => null,
    };
    if ($contents !== null) {
        file_put_contents($target, $contents);
        chmod($target, 0444);
    }
    symlink($target, $directory.'/.env');
    file_put_contents($directory.'/.env.example', "APP_KEY=\nKEEP=template-data\n");
    file_put_contents($directory.'/generated-key', $key);
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\ncase \"\$*\" in *php-fpm.d/www.conf*) exit 0 ;; esac\nexec /usr/bin/sed \"\$@\"\n");
    chmod($directory.'/bin/sed', 0755);
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
set -e
echo invoked >> "$APP_PATH/storage/logs/env-commands.log"
case "$*" in
    *key:generate*) cat "$APP_PATH/generated-key" ;;
    *tinker*) echo 1 ;;
esac
BASH);
    chmod($directory.'/bin/php', 0755);
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_KEY=', 'DB_HOST=',
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', 'bin/development-entrypoint.sh',
        'sh', '-c', 'echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Environment file must not be a symbolic link: '.$directory.'/.env')
        ->and($process->getOutput())->not->toContain('worker-started', $key)
        ->and(readlink($directory.'/.env'))->toBe($target)
        ->and(file_exists($directory.'/storage/logs/env-commands.log'))->toBeFalse()
        ->and(glob($directory.'/.env-key.*'))->toBe([]);
    if ($contents === null) {
        expect(file_exists($target))->toBeFalse();
    } else {
        expect(file_get_contents($target))->toBe($contents)
            ->and(fileperms($target) & 0777)->toBe(0444);
    }
})->with(['missing', 'empty', 'without key', 'with key']);

it('refuses redirected environment writes during key generation', function (bool $existing, string $intercept): void {
    $directory = $this->runtimeDirectory;
    $contents = "APP_KEY=\nKEEP=application-data\n";
    $protected = "APP_KEY=\nKEEP=host-data\n";
    $key = 'base64:'.base64_encode(random_bytes(32));
    file_put_contents($directory.'/.env.example', $contents);
    file_put_contents($directory.'/host-file', $protected);
    chmod($directory.'/host-file', 0444);
    file_put_contents($directory.'/generated-key', $key);
    if ($existing) {
        laravelRuntimeProcess(['install', '-m', '0444', $directory.'/.env.example', $directory.'/.env'], $directory)->mustRun();
    }
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
case "$*" in
    *key:generate*) cat "$APP_PATH/generated-key" ;;
    *tinker*) echo 1 ;;
esac
BASH);
    chmod($directory.'/bin/php', 0755);
    file_put_contents($directory.'/bin/sed', <<<'BASH'
#!/bin/sh
set -e
case "$*" in *php-fpm.d/www.conf*) exit 0 ;; esac
/usr/bin/sed "$@"
if [ "$INTERCEPT_WRITE" = sed ]; then
    mv "$APP_PATH/.env" "$APP_PATH/original-env"
    ln -s "$APP_PATH/host-file" "$APP_PATH/.env"
fi
BASH);
    chmod($directory.'/bin/sed', 0755);
    file_put_contents($directory.'/bin/dd', <<<'BASH'
#!/bin/sh
set -e
for argument in "$@"; do
    case "$argument" in
        if="$APP_PATH"/.env-key.*)
            if [ "$INTERCEPT_WRITE" = dd ]; then
                mv "$APP_PATH/.env" "$APP_PATH/original-env"
                ln -s "$APP_PATH/host-file" "$APP_PATH/.env"
            fi
            ;;
    esac
done
exec /usr/bin/dd "$@"
BASH);
    chmod($directory.'/bin/dd', 0755);
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_KEY=', 'DB_HOST=',
        'INTERCEPT_WRITE='.$intercept, 'PATH='.$directory.'/bin:'.getenv('PATH'),
        'bash', 'bin/development-entrypoint.sh', 'sh', '-c', 'echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain($directory.'/.env')
        ->and($process->getOutput())->not->toContain('worker-started', $key)
        ->and(file_get_contents($directory.'/host-file'))->toBe($protected)
        ->and(fileperms($directory.'/host-file') & 0777)->toBe(0444)
        ->and(file_get_contents($directory.'/original-env'))->toBe($contents)
        ->and(readlink($directory.'/.env'))->toBe($directory.'/host-file')
        ->and(glob($directory.'/.env-key.*'))->toBe([]);
})->with(['new environment' => false, 'existing environment' => true])
    ->with(['before final validation' => 'sed', 'after final validation' => 'dd']);

it('refuses deployment startup without a shared key even when the image has a key', function (string $role, bool $empty): void {
    $directory = $this->runtimeDirectory;
    file_put_contents($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
    $process = laravelRuntimeProcess(['env', '-u', 'APP_KEY', 'APP_PATH='.$directory,
        'ERNIE_CONTAINER_ROLE='.$role, ...($empty ? ['APP_KEY='] : []),
        'bash', 'bin/production-entrypoint.sh', 'sh', '-c', 'echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Set one shared APP_KEY')
        ->and($process->getOutput())->not->toContain('worker-started')
        ->and(is_dir($directory.'/storage'))->toBeFalse();
})->with(['app', 'queue', 'scheduler'])->with(['unset key' => false, 'empty key' => true]);

it('preserves an explicit key for subsequent commands and the final process', function (string $role, string $entrypoint): void {
    $directory = $this->runtimeDirectory;
    $key = 'base64:'.base64_encode(random_bytes(32));
    file_put_contents($directory.'/.env.example', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
set -e
case "$*" in
    *key:generate*) exit 99 ;;
    *optimize*|*runtime:key-probe*) "$REAL_PHP" -d memory_limit=2G "$APP_PATH/key-consumer.php" fingerprint ;;
esac
BASH);
    chmod($directory.'/bin/php', 0755);
    file_put_contents($directory.'/bin/php-fpm', <<<'BASH'
#!/bin/sh
if [ "${1:-}" = -tt ]; then exit 0; fi
exec "$REAL_PHP" -d memory_limit=2G "$APP_PATH/key-consumer.php" fingerprint
BASH);
    chmod($directory.'/bin/php-fpm', 0755);
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\nexit 0\n");
    chmod($directory.'/bin/sed', 0755);

    $command = $role === 'app' ? ['php-fpm'] : ['php', 'artisan', 'runtime:key-probe'];
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_ENV=production', 'APP_KEY='.$key,
        'DB_HOST=', 'ERNIE_RUN_MIGRATIONS=0', 'ERNIE_CONTAINER_ROLE='.$role, 'REAL_PHP='.PHP_BINARY,
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', 'bin/'.$entrypoint.'-entrypoint.sh', ...$command], $directory);
    $process->mustRun();

    $fingerprint = 'key-fingerprint:'.hash('sha256', base64_decode(substr($key, 7)));
    expect(substr_count($process->getOutput(), $fingerprint))->toBe($entrypoint === 'production' ? 2 : 1)
        ->and($process->getOutput())->not->toContain($key)
        ->and(file_get_contents($directory.'/.env'))->toBe(file_get_contents($directory.'/.env.example'));
})->with(['app', 'queue', 'scheduler'])->with(['production', 'development']);

it('initializes one shared development key during concurrent starts and reuses it on restart', function (): void {
    $directory = $this->runtimeDirectory;
    file_put_contents($directory.'/.env.example', "APP_KEY=\nDB_PASSWORD=fixture-value\n");
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\ncase \"\$*\" in *php-fpm.d/www.conf*) exit 0 ;; esac\nexec /usr/bin/sed \"\$@\"\n");
    chmod($directory.'/bin/sed', 0755);
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
set -e
case "$*" in
    *key:generate*)
        test ! -w "$APP_PATH/.env"
        echo generated >> "$APP_PATH/storage/logs/key-generations"
        sleep 0.1
        "$REAL_PHP" -d memory_limit=2G "$APP_PATH/key-consumer.php" generate
        ;;
    *runtime:key-probe*) "$REAL_PHP" -d memory_limit=2G "$APP_PATH/key-consumer.php" fingerprint ;;
    *tinker*) echo 1 ;;
esac
BASH);
    chmod($directory.'/bin/php', 0755);
    $script = <<<'BASH'
set -e
pids=()
for worker in {1..4}; do
    bash bin/development-entrypoint.sh php artisan runtime:key-probe > "worker-$worker.log" &
    pids+=("$!")
done
status=0
for pid in "${pids[@]}"; do wait "$pid" || status=1; done
test "$status" = 0
bash bin/development-entrypoint.sh php artisan runtime:key-probe > restart.log
BASH;
    laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_KEY=', 'DB_HOST=', 'REAL_PHP='.PHP_BINARY,
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', '-c', $script], $directory)->mustRun();

    preg_match('/^APP_KEY=base64:([^\r\n]+)/m', file_get_contents($directory.'/.env'), $matches);
    $fingerprint = 'key-fingerprint:'.hash('sha256', base64_decode($matches[1]));
    foreach (['worker-1', 'worker-2', 'worker-3', 'worker-4', 'restart'] as $worker) {
        expect(file_get_contents($directory.'/'.$worker.'.log'))->toContain($fingerprint);
    }
    expect(file_get_contents($directory.'/storage/logs/key-generations'))->toBe("generated\n")
        ->and(fileowner($directory.'/.env'))->toBe(0)
        ->and(fileperms($directory.'/.env') & 0777)->toBe(0644);
});

it('keeps environment contents and permissions when key generation fails', function (string $entrypoint, int $exitCode): void {
    $directory = $this->runtimeDirectory;
    $contents = "APP_KEY=\nDB_PASSWORD=fixture-value\n";
    file_put_contents($directory.'/.env.example', $contents);
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
case "$*" in
    *key:generate*)
        test ! -w "$APP_PATH/.env" || exit 99
        echo 'Unexpected command output'
        exit "$KEY_EXIT_CODE"
        ;;
esac
exit 0
BASH);
    chmod($directory.'/bin/php', 0755);
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\nexit 0\n");
    chmod($directory.'/bin/sed', 0755);

    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_ENV=production', 'APP_KEY=',
        'DB_HOST=', 'ERNIE_RUN_MIGRATIONS=0', 'ERNIE_CONTAINER_ROLE=queue', 'KEY_EXIT_CODE='.$exitCode,
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', 'bin/'.$entrypoint.'-entrypoint.sh',
        'sh', '-c', 'echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe($exitCode === 0 ? 1 : $exitCode)
        ->and($process->getOutput())->not->toContain('worker-started', 'Unexpected command output')
        ->and(file_get_contents($directory.'/.env'))->toBe($contents)
        ->and(fileowner($directory.'/.env'))->toBe(0)
        ->and(fileperms($directory.'/.env') & 0777)->toBe(0644)
        ->and(glob($directory.'/.env-key.*'))->toBe([]);
})->with(['development'])->with(['command error' => 17, 'invalid output' => 0]);

it('removes the temporary environment file when preparing its contents fails', function (): void {
    $directory = $this->runtimeDirectory;
    $key = 'base64:'.base64_encode(random_bytes(32));
    file_put_contents($directory.'/generated-key', $key);
    $contents = "APP_KEY=\nDB_PASSWORD=fixture-value\n";
    file_put_contents($directory.'/.env.example', $contents);
    laravelRuntimeProcess(['install', '-m', '0444', $directory.'/.env.example', $directory.'/.env'], $directory)->mustRun();
    file_put_contents($directory.'/bin/php', "#!/bin/sh\ncat \"\$APP_PATH/generated-key\"\n");
    chmod($directory.'/bin/php', 0755);
    file_put_contents($directory.'/bin/sed', "#!/bin/sh\necho partial-configuration\nexit 17\n");
    chmod($directory.'/bin/sed', 0755);

    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'PATH='.$directory.'/bin:'.getenv('PATH'),
        'bash', '-ec', 'source bin/laravel-runtime.sh; generate_application_key; echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(17)
        ->and($process->getOutput())->not->toContain('worker-started', 'partial-configuration', $key)
        ->and(file_get_contents($directory.'/.env'))->toBe($contents)
        ->and(fileowner($directory.'/.env'))->toBe(0)
        ->and(fileperms($directory.'/.env') & 0777)->toBe(0444)
        ->and(glob($directory.'/.env-key.*'))->toBe([]);
});

it('does not start a worker after production cache optimization fails', function (): void {
    $directory = $this->runtimeDirectory;
    file_put_contents($directory.'/bin/php', <<<'BASH'
#!/bin/sh
case "$*" in
    *optimize*) exit 17 ;;
esac
exit 0
BASH);
    chmod($directory.'/bin/php', 0755);
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'APP_ENV=production', 'APP_KEY=fixture',
        'DB_HOST=', 'ERNIE_RUN_MIGRATIONS=0', 'ERNIE_CONTAINER_ROLE=queue',
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', 'bin/production-entrypoint.sh',
        'sh', '-c', 'echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(17)
        ->and($process->getOutput())->not->toContain('worker-started');
});

it('preserves the FPM master identity and forwards worker arguments and exit status', function (): void {
    $directory = $this->runtimeDirectory;
    file_put_contents($directory.'/bin/php-fpm', "#!/bin/sh\nid -u\n");
    chmod($directory.'/bin/php-fpm', 0755);
    $master = laravelRuntimeProcess(['env', 'PATH='.$directory.'/bin:'.getenv('PATH'),
        'bash', '-c', 'source bin/laravel-runtime.sh; exec_laravel_runtime php-fpm'], $directory);
    $master->mustRun();
    expect(trim($master->getOutput()))->toBe('0');

    $worker = laravelRuntimeProcess(['bash', '-c',
        'source bin/laravel-runtime.sh; exec_laravel_runtime sh -c \'id -un; printf "%s" "$1"; exit 23\' sh "$1"',
        'test', 'argument with spaces'], $directory);
    $worker->run();
    expect($worker->getExitCode())->toBe(23)
        ->and($worker->getOutput())->toBe("www-data\nargument with spaces");
});

it('rejects a symlinked public directory before preparing the storage link', function (bool $dangling, bool $existing): void {
    $directory = $this->runtimeDirectory;
    $target = $directory.'/redirected-public';
    if (! $dangling) {
        mkdir($target);
        file_put_contents($target.'/keep.txt', 'existing data');
        if ($existing) {
            symlink($directory.'/old-storage', $target.'/storage');
        }
    }
    rmdir($directory.'/public');
    symlink($target, $directory.'/public');
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-ec', 'source bin/laravel-runtime.sh; prepare_storage_link; echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Public directory must not be a symbolic link: '.$directory.'/public')
        ->and($process->getOutput())->not->toContain('worker-started')
        ->and(readlink($directory.'/public'))->toBe($target);
    if ($dangling) {
        expect(file_exists($target))->toBeFalse();
    } else {
        expect(scandir($target))->toBe($existing ? ['.', '..', 'keep.txt', 'storage'] : ['.', '..', 'keep.txt'])
            ->and(file_get_contents($target.'/keep.txt'))->toBe('existing data');
        if ($existing) {
            expect(readlink($target.'/storage'))->toBe($directory.'/old-storage');
        }
    }
})->with([
    'without a storage link' => [false, false],
    'with an existing storage link' => [false, true],
    'dangling public symlink' => [true, false],
]);

it('does not replace an actual directory when preparing the public storage link', function (): void {
    $directory = $this->runtimeDirectory;
    mkdir($directory.'/public/storage');
    file_put_contents($directory.'/public/storage/keep.txt', 'existing data');
    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-c', 'source bin/laravel-runtime.sh; prepare_storage_link'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('is not a symbolic link')
        ->and(file_get_contents($directory.'/public/storage/keep.txt'))->toBe('existing data');
});

it('prepares the storage link concurrently without failures or gaps', function (bool $existing): void {
    $directory = $this->runtimeDirectory;
    $script = <<<'BASH'
set -euo pipefail
source bin/laravel-runtime.sh
mkdir -p "$STORAGE_PATH/app/public" "$APP_PATH/old-public"
printf 'existing data' > "$APP_PATH/old-public/keep.txt"
if [ "$1" = existing ]; then
    ln -s "$APP_PATH/old-public" "$APP_PATH/public/storage"
fi

pids=()
for worker in {1..12}; do
    (
        while [ ! -e start ]; do sleep 0.01; done
        for attempt in {1..20}; do prepare_storage_link; done
    ) &
    pids+=("$!")
done

# A reader must never observe the existing link disappearing during replacement.
if [ "$1" = existing ]; then
    (
        while [ ! -e start ]; do sleep 0.01; done
        while [ ! -e stop ]; do
            if [ ! -L "$APP_PATH/public/storage" ]; then
                echo 'Storage link disappeared during replacement' >&2
                exit 1
            fi
        done
    ) &
    reader=$!
fi

touch start
status=0
for pid in "${pids[@]}"; do wait "$pid" || status=1; done
touch stop
if [ "$1" = existing ]; then wait "$reader" || status=1; fi
exit "$status"
BASH;
    laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'bash', '-c', $script, 'concurrent-storage-links', $existing ? 'existing' : 'missing'], $directory)->mustRun();

    expect(readlink($directory.'/public/storage'))->toBe($directory.'/storage/app/public')
        ->and(scandir($directory.'/public'))->toBe(['.', '..', 'storage'])
        ->and(scandir($directory.'/storage/app/public'))->toBe(['.', '..'])
        ->and(scandir($directory.'/old-public'))->toBe(['.', '..', 'keep.txt'])
        ->and(file_get_contents($directory.'/old-public/keep.txt'))->toBe('existing data');
})->with(['missing link' => false, 'existing link' => true]);

it('cleans up temporary storage links when replacement fails', function (): void {
    $directory = $this->runtimeDirectory;
    symlink($directory.'/old-public', $directory.'/public/storage');
    file_put_contents($directory.'/bin/mv', "#!/bin/sh\nexit 17\n");
    chmod($directory.'/bin/mv', 0755);

    $process = laravelRuntimeProcess(['env', 'APP_PATH='.$directory, 'STORAGE_PATH='.$directory.'/storage',
        'PATH='.$directory.'/bin:'.getenv('PATH'), 'bash', '-ec',
        'source bin/laravel-runtime.sh; prepare_storage_link; echo worker-started'], $directory);
    $process->run();

    expect($process->getExitCode())->toBe(17)
        ->and($process->getOutput())->not->toContain('worker-started')
        ->and(readlink($directory.'/public/storage'))->toBe($directory.'/old-public')
        ->and(scandir($directory.'/public'))->toBe(['.', '..', 'storage']);
});
