import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { createPlaywrightNginxConfig } from './playwright-test-routing.mjs';
import { createTestTimings } from './test-runtime.mjs';

const compose = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
const verifyBrowserBackend = [
    "require 'vendor/autoload.php';",
    "$app = require 'bootstrap/app.php';",
    '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
    "if (config('mail.default') !== 'log' || config('queue.default') !== 'sync'",
    "    || config('view.compiled') !== '/tmp/ernie-playwright-views'",
    "    || config('inertia.devtools.enabled') !== false",
    '    || !app(App\\Services\\DataPublicationTeamRecipientService::class)->isAvailable()',
    '    || !(app(App\\Services\\DataCiteRegistrationService::class) instanceof App\\Services\\FakeDataCiteRegistrationService)) {',
    '    fwrite(STDERR, "Browser backend requires synchronous log mail, a team recipient, fake DataCite, a native view cache, and disabled Inertia DevTools.\\n"); exit(1);',
    '}',
].join('\n');

function run(command, args) {
    const result = spawnSync(command, args, { stdio: 'inherit' });
    if (result.error || result.signal || result.status !== 0) {
        const error = result.error ?? new Error(`${command} exited with ${result.signal ?? result.status ?? 1}.`);
        error.exitCode = result.status ?? 1;
        throw error;
    }
}

// Nginx resolves the FPM upstream at configuration load. Recreating the app
// can change its Docker IP, so refresh it after both configuration changes.
function reloadWebserver(execute) {
    execute('docker', [...compose, 'exec', '-T', 'webserver', 'nginx', '-s', 'reload']);
}

function prepareBrowserRouting(rootDirectory) {
    const source = readFileSync(new URL('../docker/nginx/conf.d/dev.conf', import.meta.url), 'utf8');
    const configuration = createPlaywrightNginxConfig(source);
    const path = join(rootDirectory, 'storage', 'framework', 'testing', 'playwright-nginx.conf');
    mkdirSync(dirname(path), { recursive: true });
    try {
        writeFileSync(path, configuration, { flag: 'wx' });
    } catch (error) {
        if (error.code === 'EEXIST')
            throw new Error('Browser routing configuration already exists; verify that no other browser wrapper is active.', { cause: error });
        throw error;
    }
    return () => unlinkSync(path);
}

export function runPlaywrightDevstack(
    args,
    {
        execute = run,
        timings = createTestTimings('playwright'),
        assets = process.env.ERNIE_PLAYWRIGHT_ASSETS?.trim() || 'vite',
        rootDirectory = process.cwd(),
    } = {},
) {
    const test = () =>
        execute(process.execPath, ['./node_modules/@playwright/test/cli.js', 'test', '--config=playwright.devstack.config.ts', ...args]);
    if (args.includes('--list') || args.includes('--help')) {
        timings.measure('Discovery', test);
        return;
    }
    if (!['vite', 'build'].includes(assets)) {
        throw new Error('ERNIE_PLAYWRIGHT_ASSETS must be vite or build.');
    }
    timings.measure('Verify Vite dependencies', () =>
        execute('docker', [...compose, 'exec', '-T', 'vite', 'node', 'scripts/verify-vite-dependencies.mjs']),
    );
    timings.measure('Verify PHP dependencies', () =>
        execute('docker', [...compose, 'exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs']),
    );
    const hotPath = join(rootDirectory, 'public', 'hot');
    const removeBrowserRouting = prepareBrowserRouting(rootDirectory);
    let originalHot;
    let backendRestored = false;
    try {
        timings.measure('Browser backend preparation', () =>
            execute('docker', [...compose, '-f', 'docker-compose.playwright-test.yml', 'up', '-d', '--wait', 'app', 'webserver']),
        );
        timings.measure('Refresh browser routing', () => reloadWebserver(execute));
        timings.measure('Verify browser backend', () =>
            execute('docker', [...compose, 'exec', '-T', 'app', 'php', '-d', 'memory_limit=2G', '-r', verifyBrowserBackend]),
        );
        if (assets === 'build') {
            timings.measure('Browser asset workspace', () =>
                execute('docker', [...compose, 'exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh']),
            );
            timings.measure('Browser asset build', () =>
                execute('docker', [...compose, 'exec', '-T', 'app', 'sh', '/var/www/html/scripts/build-playwright-assets.sh']),
            );
            timings.measure('Copy browser assets', () =>
                execute('docker', [...compose, 'cp', 'app:/var/www/pest-workspace/public/build', join(rootDirectory, 'public')]),
            );
            if (existsSync(hotPath)) {
                originalHot = readFileSync(hotPath);
                unlinkSync(hotPath);
            }
        }
        // A listening FPM socket and a reload signal do not prove that public
        // Nginx routing has switched to the recreated app yet.
        timings.measure('Browser HTTP readiness', () =>
            execute(process.execPath, ['scripts/wait-for-playwright-backend.mjs', process.env.PLAYWRIGHT_BASE_URL ?? 'https://ernie.localhost:3333']),
        );
        timings.measure('Playwright', test);
    } finally {
        try {
            if (originalHot !== undefined) writeFileSync(hotPath, originalHot);
        } finally {
            try {
                timings.measure('Restore development backend', () => execute('docker', [...compose, 'up', '-d', '--wait', 'app', 'webserver']));
                backendRestored = true;
            } finally {
                try {
                    timings.measure('Refresh development routing', () => reloadWebserver(execute));
                } finally {
                    // Keep the mounted file available if service restoration failed.
                    if (backendRestored) removeBrowserRouting();
                }
            }
        }
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const timings = createTestTimings('playwright');
    try {
        runPlaywrightDevstack(process.argv.slice(2), { timings });
    } catch (error) {
        console.error(`[playwright] ${error.message}`);
        process.exitCode = error.exitCode ?? 1;
    } finally {
        timings.finish(process.exitCode ?? 0);
    }
}
