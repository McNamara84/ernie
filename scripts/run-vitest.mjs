import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';
import { performance } from 'node:perf_hooks';

import { createTestTimings } from './test-runtime.mjs';
import { verifyPhpDependencies } from './verify-php-dependencies.mjs';
import {
    resolveAffectedArguments,
    syncWayfinderFiles,
    vitestInputFingerprint,
    wayfinderCacheMatches,
    wayfinderFileHashes,
    wayfinderInputFingerprint,
} from './vitest-runtime.mjs';

const expectedNode = readFileSync(new URL('../.node-version', import.meta.url), 'utf8').trim();
if (process.versions.node !== expectedNode) {
    throw new Error(`Vitest requires Node ${expectedNode}; received ${process.versions.node}.`);
}

const args = process.argv.slice(2);
const needsRoutes = !args.some((arg) =>
    ['--help', '--version', '--clearCache', '--merge-reports'].some((flag) => arg === flag || arg.startsWith(`${flag}=`)),
);
const fingerprint = vitestInputFingerprint();
const fingerprintFile = '.vitest/affected-inputs';
let previousFingerprint;
try {
    previousFingerprint = readFileSync(fingerprintFile, 'utf8');
} catch (error) {
    if (error.code !== 'ENOENT') throw error;
}
const selection = resolveAffectedArguments(args, fingerprint, previousFingerprint);
process.argv.splice(2, process.argv.length - 2, ...selection.args);

const timings = createTestTimings('vitest');
timings.report.mode = process.argv[2] ?? 'watch';
let vitestStartedAt;
process.once('exit', (exitCode) => {
    if (exitCode === 0 && selection.fullRun) {
        mkdirSync('.vitest', { recursive: true });
        writeFileSync(fingerprintFile, fingerprint);
    }
    if (vitestStartedAt !== undefined) {
        timings.report.phases.push({
            name: 'Vitest',
            status: exitCode === 0 ? 'passed' : 'failed',
            durationMs: performance.now() - vitestStartedAt,
        });
    }
    timings.finish(exitCode);
});

const hostWayfinderProbeTimeoutMs = 20_000;
const dockerWayfinderCommand =
    'docker compose --env-file .env.docker -f docker-compose.dev.yml exec -T --user www-data app php -d memory_limit=2G artisan ernie:wayfinder-generate';

function resolveHostPhpCommand() {
    if (process.platform !== 'win32') {
        return 'php';
    }

    const result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', '(Get-Command php -ErrorAction Stop).Source'], {
        encoding: 'utf8',
    });
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
const hostExecutable = process.platform === 'win32' && hostPhpCommand ? `"${hostPhpCommand}"` : 'php';
const hostWayfinderCommand = `${hostExecutable} -d memory_limit=2G artisan ernie:wayfinder-generate --with-form`;

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

        try {
            verifyPhpDependencies(
                JSON.parse(readFileSync('composer.lock', 'utf8')),
                JSON.parse(readFileSync('vendor/composer/installed.json', 'utf8')),
            );
        } catch {
            warnWayfinderFallback({ error: new Error('Host Composer dependencies are missing or differ from composer.lock.') }, outputPath);
            return false;
        }

        // Resolve Herd's php.bat shim once, then execute its selected php.exe
        // directly. A bare Node spawn prefers a later php.exe from Herd Lite,
        // while spawning PowerShell would leave PHP alive after a probe timeout.
        const result = spawnSync(
            hostPhpCommand,
            ['-d', 'memory_limit=2G', 'artisan', 'ernie:wayfinder-generate', '--with-form', `--path=${outputPath}`],
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

function verifyDockerPhpDependencies() {
    const result = spawnSync(
        'docker',
        ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', 'exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs'],
        { stdio: 'inherit' },
    );
    if (result.error || result.signal || result.status !== 0) {
        throw result.error ?? new Error(`Docker PHP dependency verification failed: ${result.signal ?? result.status ?? 1}.`);
    }
}

if (needsRoutes && !process.env.WAYFINDER_COMMAND) {
    const hostIsAvailable = timings.measure('Wayfinder preflight', canRunHostWayfinder);
    timings.report.wayfinder = hostIsAvailable ? 'host' : 'docker';
    if (!hostIsAvailable) {
        timings.measure('Verify Docker PHP dependencies', verifyDockerPhpDependencies);
    }
    // Use the checked executable and memory limit for actual generation too.
    process.env.WAYFINDER_COMMAND = hostIsAvailable ? hostWayfinderCommand : dockerWayfinderCommand;
} else {
    timings.report.wayfinder = 'configured';
}

if (needsRoutes) {
    timings.measure('Generate Wayfinder sources', () => {
        const workRoot = resolve('.vitest');
        mkdirSync(workRoot, { recursive: true });
        const routeFingerprint = wayfinderInputFingerprint(process.env.WAYFINDER_COMMAND);
        const manifestPath = join(workRoot, 'wayfinder-manifest.json');
        let manifest;
        try {
            manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
        }
        const destination = resolve('resources/js');
        if (wayfinderCacheMatches(manifest, routeFingerprint, destination)) return;
        const output = mkdtempSync(join(workRoot, 'wayfinder-'));
        if (!output.startsWith(`${workRoot}\\`) && !output.startsWith(`${workRoot}/`)) {
            throw new Error('Wayfinder output must remain inside .vitest.');
        }
        try {
            // A relative workspace path also resolves inside the Docker bind mount.
            const outputPath = relative(process.cwd(), output).replaceAll('\\', '/');
            const command = `${process.env.WAYFINDER_COMMAND} --with-form --path=${outputPath}`;
            const result = spawnSync(command, { shell: true, stdio: 'inherit' });
            if (result.error || result.signal || result.status !== 0) {
                throw result.error ?? new Error(`Wayfinder generation failed: ${result.signal ?? result.status ?? 1}.`);
            }
            const files = wayfinderFileHashes(output);
            if (!files['wayfinder/index.ts']) throw new Error('Wayfinder generation produced no helper; existing output was preserved.');
            syncWayfinderFiles(output, destination, manifest?.files);
            writeFileSync(manifestPath, JSON.stringify({ fingerprint: routeFingerprint, files }));
        } finally {
            rmSync(output, { recursive: true, force: true });
        }
    });
}

vitestStartedAt = performance.now();
await import('../node_modules/vitest/vitest.mjs');
