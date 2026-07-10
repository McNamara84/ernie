import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const webStorageFlag = '--no-experimental-webstorage';
const ignoredStderrLines = new Set(['Could not parse CSS stylesheet', 'Not implemented: navigation to another Document']);
const dockerWayfinderCommand =
    'docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T app php artisan ernie:wayfinder-generate';

function canRun(command, args = ['--version']) {
    const result = spawnSync(command, args, {
        stdio: 'ignore',
    });

    return !result.error && result.status === 0;
}

function withWebStorageFlag(nodeOptions = '') {
    const options = nodeOptions.trim();

    if (options.includes('--no-experimental-webstorage') || options.includes('--localstorage-file=')) {
        return options;
    }

    return options ? `${options} ${webStorageFlag}` : webStorageFlag;
}

function filterKnownJsdomNoise(output = '') {
    const parts = output.split(/(\r?\n)/);
    let filtered = '';

    for (let index = 0; index < parts.length; index += 2) {
        const line = parts[index] ?? '';
        const newline = parts[index + 1] ?? '';

        if (ignoredStderrLines.has(line.trim())) {
            continue;
        }

        filtered += `${line}${newline}`;
    }

    return filtered;
}

if (!process.execArgv.includes(webStorageFlag)) {
    const result = spawnSync(process.execPath, [webStorageFlag, fileURLToPath(import.meta.url), ...process.argv.slice(2)], {
        encoding: 'utf8',
        env: {
            ...process.env,
            NODE_OPTIONS: withWebStorageFlag(process.env.NODE_OPTIONS),
        },
        maxBuffer: 100 * 1024 * 1024,
        stdio: ['inherit', 'inherit', 'pipe'],
    });

    if (result.stderr) {
        process.stderr.write(filterKnownJsdomNoise(result.stderr));
    }

    if (result.error) {
        throw result.error;
    }

    if (result.signal) {
        process.kill(process.pid, result.signal);
    }

    process.exit(result.status ?? 1);
}

process.env.NODE_OPTIONS = withWebStorageFlag(process.env.NODE_OPTIONS);

if (!process.env.WAYFINDER_COMMAND && !canRun('php', ['artisan', '--version'])) {
    process.env.WAYFINDER_COMMAND = dockerWayfinderCommand;
}

await import('../node_modules/vitest/vitest.mjs');
