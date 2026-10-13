import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

import { createTestTimings } from './test-runtime.mjs';

const compose = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml', '--profile', 'test'];
const manifest = JSON.parse(readFileSync(new URL('../tests/mysql-sensitive-slices.json', import.meta.url), 'utf8'));
const resetSql = "DROP DATABASE IF EXISTS ernie_test; CREATE DATABASE ernie_test; GRANT ALL PRIVILEGES ON ernie_test.* TO 'ernie'@'%'; FLUSH PRIVILEGES;";
const prepareSql = "CREATE DATABASE IF NOT EXISTS ernie_test; GRANT ALL PRIVILEGES ON ernie_test.* TO 'ernie'@'%'; FLUSH PRIVILEGES;";
const databaseEnvironment = [
    'ERNIE_TEST_DB_CONNECTION=mysql', 'ERNIE_TEST_DB_HOST=db-test', 'ERNIE_TEST_DB_PORT=3306',
    'ERNIE_TEST_DB_DATABASE=ernie_test', 'ERNIE_TEST_DB_USERNAME=ernie', 'ERNIE_TEST_DB_PASSWORD=secret',
];

export function resolveMysqlSlices(selection) {
    const names = selection === undefined ? manifest.complete : manifest.aliases[selection] ?? [selection];
    return names.map((name) => {
        const paths = manifest.slices[name];
        if (!paths) {
            throw new Error(`Unknown MySQL test slice: ${name}`);
        }
        return { name, paths };
    });
}

function docker(args, { capture = false } = {}) {
    const result = spawnSync('docker', [...compose, ...args], {
        stdio: capture ? ['ignore', 'pipe', 'inherit'] : 'inherit',
        encoding: 'utf8',
    });
    if (result.error || result.signal || result.status !== 0) {
        const error = result.error ?? new Error(`Docker exited with ${result.signal ?? result.status ?? 1}.`);
        error.exitCode = result.status ?? 1;
        throw error;
    }
    return result.stdout?.trim();
}

// Injectable transport verifies reset ordering and failure handling without
// starting Docker or issuing DDL in the tooling tests.
export function runMysqlTests({ selection, action = 'tests', pestArgs = [], execute = docker, timings = createTestTimings('mysql') } = {}) {
    if (!['tests', 'prepare', 'reset', 'exec'].includes(action)) throw new Error(`Unknown MySQL action: ${action}`);
    const slices = action === 'tests' ? resolveMysqlSlices(selection) : [];
    if (action === 'exec' && pestArgs.length === 0) throw new Error('--exec requires a test path or filter.');
    if (pestArgs.some((argument) => argument === '-p' || argument === '--parallel' || argument.startsWith('--parallel=') || argument.startsWith('--processes'))) {
        throw new Error('MySQL slices share ernie_test and must run serially.');
    }
    timings.report.mode = selection ?? 'complete';
    timings.report.phpMemoryLimit = '2G';
    timings.report.database = 'ernie_test';
    timings.measure('Backend services', () => execute(['up', '-d', '--wait', 'db', 'redis', 'app', 'db-test']));
    timings.measure('Verify PHP dependencies', () => execute(['exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs']));
    const version = timings.measure('MySQL version', () => execute([
        'exec', '-T', 'db-test', 'mysql', '-uroot', '-prootsecret', '--batch', '--skip-column-names', '-e', 'SELECT VERSION()',
    ], { capture: true }));
    if (!/^9\.7\.\d+(?:[-\w.]*)$/u.test(version ?? '')) {
        throw new Error(`Expected the pinned MySQL 9.7 server; received ${version ?? 'no version'}. No schema was reset.`);
    }
    timings.report.mysql = version;
    console.log(`[mysql] Server ${version}; isolated schema ernie_test; ${slices.length} serial slices.`);

    const reset = (name, sql = resetSql) => timings.measure(`Reset: ${name}`, () => execute([
        'exec', '-T', 'db-test', 'mysql', '-uroot', '-prootsecret', '-e', sql,
    ]));
    if (action === 'prepare' || action === 'reset') {
        reset(action, action === 'prepare' ? prepareSql : resetSql);
        return;
    }

    const workspace = action === 'tests' && selection === undefined;
    if (workspace) {
        timings.measure('Workspace preparation', () => execute([
            'exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh',
        ]));
    }
    for (const { name, paths } of action === 'exec' ? [{ name: 'focused', paths: [] }] : slices) {
        if (action !== 'exec') reset(name);
        timings.measure(`Tests: ${name}`, () => execute([
            'exec', '-T', ...(workspace ? ['-w', '/var/www/pest-workspace'] : []),
            ...databaseEnvironment.flatMap((value) => ['-e', value]),
            'app', 'php', '-d', 'memory_limit=2G', './vendor/bin/pest', '--no-coverage', ...paths, ...pestArgs,
        ]));
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const timings = createTestTimings('mysql');
    const args = process.argv.slice(2);
    let selection;
    let action = 'tests';
    if (['--prepare', '--reset', '--exec'].includes(args[0])) action = args.shift().slice(2);
    if (args[0] === '--slice') {
        args.shift();
        selection = args.shift();
        if (!selection) {
            console.error('--slice requires a name.');
            timings.finish(1);
            process.exit(1);
        }
    }
    try {
        runMysqlTests({ selection, action, pestArgs: args, timings });
    } catch (error) {
        console.error(`[mysql] ${error.message}`);
        process.exitCode = error.exitCode ?? 1;
    } finally {
        timings.finish(process.exitCode ?? 0);
    }
}
