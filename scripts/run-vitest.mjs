import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const webStorageFlag = '--no-experimental-webstorage';
const ignoredStderrLines = new Set(['Could not parse CSS stylesheet', 'Not implemented: navigation to another Document']);
const hostWayfinderCommand = 'php artisan ernie:wayfinder-generate';
const dockerWayfinderCommand =
    'docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T app php artisan ernie:wayfinder-generate';

function commandFailureReason(result) {
    if (result.error) {
        return result.error.message;
    }

    if (result.signal) {
        return `terminated by signal ${result.signal}`;
    }

    return `exited with status ${result.status ?? 1}`;
}

function compactOutput(output = '') {
    const trimmed = output.trim();

    if (!trimmed) {
        return '';
    }

    return trimmed.length > 1200 ? `${trimmed.slice(0, 1200)}...` : trimmed;
}

function warnWayfinderFallback(result, outputPath) {
    const output = compactOutput(`${result.stdout ?? ''}${result.stderr ?? ''}`);

    console.warn('[vitest] Host Wayfinder check failed; using Docker fallback for route generation.');
    console.warn(`[vitest] Checked command: ${hostWayfinderCommand} --path=${outputPath}`);
    console.warn(`[vitest] Reason: ${commandFailureReason(result)}`);

    if (output) {
        console.warn(`[vitest] Output:\n${output}`);
    }

    console.warn('[vitest] Set WAYFINDER_COMMAND to override the route generation command.');
}

function canRunHostWayfinder() {
    const outputPath = mkdtempSync(join(tmpdir(), 'ernie-wayfinder-'));

    try {
        const result = spawnSync('php', ['artisan', 'ernie:wayfinder-generate', `--path=${outputPath}`], {
            encoding: 'utf8',
            maxBuffer: 10 * 1024 * 1024,
        });

        if (!result.error && result.status === 0) {
            return true;
        }

        warnWayfinderFallback(result, outputPath);

        return false;
    } finally {
        rmSync(outputPath, { force: true, recursive: true });
    }
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

if (!process.env.WAYFINDER_COMMAND && !canRunHostWayfinder()) {
    process.env.WAYFINDER_COMMAND = dockerWayfinderCommand;
}

await import('../node_modules/vitest/vitest.mjs');
