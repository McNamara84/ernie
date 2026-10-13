import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

import { createTestTimings } from './test-runtime.mjs';

const compose = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
function docker(args) {
    const result = spawnSync('docker', [...compose, ...args], { stdio: 'inherit' });
    if (result.error || result.signal || result.status !== 0) {
        const error = result.error ?? new Error(`Pint exited with ${result.signal ?? result.status ?? 1}.`);
        error.exitCode = result.status ?? 1;
        throw error;
    }
}

export function runPintCheck(args, { execute = docker, timings = createTestTimings('pint') } = {}) {
    timings.measure('Backend services', () => execute(['up', '-d', '--wait', 'db', 'redis', 'app']));
    timings.measure('Verify PHP dependencies', () => execute(['exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs']));
    timings.measure('Workspace preparation', () => execute([
        'exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh',
    ]));
    timings.measure('Pint', () => execute([
        'exec', '-T', '-w', '/var/www/pest-workspace',
        '-e', 'PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/var/www/pest-workspace/storage/framework/testing/php-ini',
        'app', 'php', '-d', 'memory_limit=2G',
        './vendor/bin/pint', ...args, '--test', '--parallel',
    ]));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const timings = createTestTimings('pint');
    try {
        runPintCheck(process.argv.slice(2), { timings });
    } catch (error) {
        console.error(`[pint] ${error.message}`);
        process.exitCode = error.exitCode ?? 1;
    } finally {
        timings.finish(process.exitCode ?? 0);
    }
}
