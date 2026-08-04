import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const hostWayfinderCommand = 'php artisan ernie:wayfinder-generate --with-form';
const hostWayfinderProbeTimeoutMs = 10_000;
const dockerWayfinderCommand =
    'docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T app php artisan ernie:wayfinder-generate';

function commandFailureReason(result) {
    if (result.error?.code === 'ETIMEDOUT') {
        return `timed out after ${hostWayfinderProbeTimeoutMs}ms`;
    }

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
        const result = spawnSync('php', ['artisan', 'ernie:wayfinder-generate', '--with-form', `--path=${outputPath}`], {
            encoding: 'utf8',
            maxBuffer: 10 * 1024 * 1024,
            timeout: hostWayfinderProbeTimeoutMs,
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

if (!process.env.WAYFINDER_COMMAND && !canRunHostWayfinder()) {
    process.env.WAYFINDER_COMMAND = dockerWayfinderCommand;
}

await import('../node_modules/vitest/vitest.mjs');
