import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { copyFileSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

import { composeArguments } from './docker-compose.mjs';

const token = randomBytes(16).toString('hex');
const temporary = mkdtempSync(join(tmpdir(), 'ernie-dcat-'));
const workspace = `/tmp/ernie-dcat-${token}`;
const filename = `dcat-ap-${token}.json`;
const ci = process.env.GITHUB_ACTIONS === 'true' && !process.env.ERNIE_PHP86_COMPOSE;
const environment = { ...process.env, ERNIE_DCAT_REPORT_TOKEN: token };
const compose = composeArguments();

function run(command, args) {
    const result = spawnSync(command, args, { stdio: 'inherit', env: environment });
    if (result.error || result.signal || result.status !== 0) {
        throw result.error ?? new Error(`${command} exited with ${result.signal ?? result.status}.`);
    }
}

try {
    const args = ['tests/pest/Feature/DcatApContractTest.php', '--compact'];
    if (ci) {
        run('php', ['-d', 'memory_limit=2G', './vendor/bin/pest', '--no-coverage', ...args]);
        copyFileSync(join('storage/framework/testing', filename), join(temporary, 'exports.json'));
    } else {
        run(process.execPath, ['scripts/run-pest.mjs', ...args]);
        run('docker', [...compose, 'cp', `app:${workspace}/storage/framework/testing/${filename}`, join(temporary, 'exports.json')]);
    }
    const output = resolve('storage/logs/dcat-ap');
    mkdirSync(output, { recursive: true });
    copyFileSync(join(temporary, 'exports.json'), join(output, 'exports.json'));
    run('docker', ['build', '--quiet', '-t', 'ernie-dcat-ap-validator:3.0.1', 'scripts/dcat-ap']);
    run('docker', [
        'run',
        '--rm',
        '--network=none',
        '--hostname=localhost',
        '--read-only',
        '--tmpfs',
        '/tmp:rw,noexec,nosuid',
        '-v',
        `${resolve('.')}:/workspace:ro`,
        '-v',
        `${temporary}:/reports`,
        'ernie-dcat-ap-validator:3.0.1',
        '/reports/exports.json',
    ]);
    copyFileSync(join(temporary, 'validation.json'), join(output, 'validation.json'));
} catch (error) {
    console.error(`[dcat-ap] ${error.message}`);
    process.exitCode = 1;
} finally {
    if (ci) {
        rmSync(join('storage/framework/testing', filename), { force: true });
    } else {
        try {
            run('docker', [...compose, 'exec', '-T', 'app', 'rm', '-rf', '--', workspace]);
        } catch (error) {
            console.error(`[dcat-ap] Workspace cleanup failed: ${error.message}`);
            process.exitCode = 1;
        }
    }
    rmSync(temporary, { recursive: true, force: true });
}
