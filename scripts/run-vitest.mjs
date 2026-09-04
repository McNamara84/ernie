import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const hostWayfinderProbeTimeoutMs = 20_000;
const dockerWayfinderCommand =
    'docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T app php artisan ernie:wayfinder-generate';

function resolveHostPhpCommand() {
    if (process.platform !== 'win32') {
        return 'php';
    }

    const result = spawnSync(
        'powershell.exe',
        ['-NoProfile', '-NonInteractive', '-Command', '(Get-Command php -ErrorAction Stop).Source'],
        { encoding: 'utf8' },
    );
    const commandPath = result.status === 0 ? result.stdout.trim().split(/\r?\n/u)[0] : '';

    if (commandPath.toLowerCase().endsWith('.exe')) {
        return commandPath;
    }

    if (commandPath.toLowerCase().endsWith('.bat')) {
        try {
            const executablePath = readFileSync(commandPath, 'utf8').match(/"([^"\r\n]*php\.exe)"/iu)?.[1];

            if (executablePath) {
                return executablePath;
            }
        } catch {
            return null;
        }
    }

    return null;
}

const hostPhpCommand = resolveHostPhpCommand();
const hostWayfinderCommand = `${hostPhpCommand ?? 'php'} artisan ernie:wayfinder-generate --with-form`;

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
        if (!hostPhpCommand) {
            warnWayfinderFallback({ error: new Error('Could not resolve the active host PHP executable.') }, outputPath);

            return false;
        }

        // Resolve Herd's php.bat shim once, then execute its selected php.exe
        // directly. A bare Node spawn prefers a later php.exe from Herd Lite,
        // while spawning PowerShell would leave PHP alive after a probe timeout.
        const result = spawnSync(
            hostPhpCommand,
            ['artisan', 'ernie:wayfinder-generate', '--with-form', `--path=${outputPath}`],
            {
                encoding: 'utf8',
                maxBuffer: 10 * 1024 * 1024,
                timeout: hostWayfinderProbeTimeoutMs,
            },
        );

        if (!result.error && result.status === 0) {
            return true;
        }

        warnWayfinderFallback(result, outputPath);

        return false;
    } finally {
        rmSync(outputPath, { force: true, maxRetries: 5, recursive: true, retryDelay: 200 });
    }
}

if (!process.env.WAYFINDER_COMMAND && !canRunHostWayfinder()) {
    process.env.WAYFINDER_COMMAND = dockerWayfinderCommand;
}

await import('../node_modules/vitest/vitest.mjs');
