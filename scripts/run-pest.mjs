import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { performance } from 'node:perf_hooks';

import { createTestTimings, resolveTestWorkers } from './test-runtime.mjs';

const composeArgs = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
const pestArgs = process.argv.slice(2);
const shardTimingCommand = pestArgs.length === 1 && pestArgs[0] === '--update-shards';
const updateShardTimings = shardTimingCommand || process.env.ERNIE_PEST_UPDATE_SHARDS === '1';
const completeSuite = pestArgs.length === 0 || shardTimingCommand;
const phpMemoryLimit = '2G';
const jsonLdReportToken = process.env.ERNIE_JSONLD_REPORT_TOKEN?.trim();
if (jsonLdReportToken && !/^[a-f0-9]{32}$/.test(jsonLdReportToken)) {
    throw new Error('Invalid JSON-LD report token.');
}
const pestWorkspace = jsonLdReportToken ? `/tmp/ernie-jsonld-${jsonLdReportToken}` : '/var/www/pest-workspace';
let processCount;
const profileArgs = process.env.ERNIE_PEST_PROFILE === '1' ? ['--profile'] : [];
const reportDirectory = process.env.ERNIE_PEST_REPORT_DIR?.trim();
const timings = createTestTimings('pest');
timings.report.mode = completeSuite ? 'complete' : 'focused';
timings.report.phpMemoryLimit = phpMemoryLimit;

try {
    processCount = resolveTestWorkers(process.env.ERNIE_PEST_PROCESSES);
} catch {
    console.error('ERNIE_PEST_PROCESSES must be a positive integer.');
    timings.finish(1);
    process.exit(1);
}
timings.report.workers = processCount;

const usesCoverageDriver = pestArgs.some(
    (argument) => argument === '--tia' || argument === '--mutate' || argument.startsWith('--coverage'),
);
// Listing the whole suite still loads its PHP definitions. Keep this read-only
// discovery off the slow host bind mount, using a freshly synchronized snapshot.
const nativeDiscovery = pestArgs.includes('--list-tests') && !usesCoverageDriver;
const nativeWorkspace = nativeDiscovery || Boolean(jsonLdReportToken);

function run(command, args) {
    const result = spawnSync(command, args, {
        stdio: 'inherit',
    });

    if (result.error || result.signal || result.status !== 0) {
        const error = result.error ?? new Error(`${command} exited with ${result.signal ?? result.status ?? 1}.`);
        error.exitCode = result.status ?? 1;
        error.signal = result.signal;
        throw error;
    }
}

function dockerPest(args, { coverage = false, workspace = false } = {}) {
    const usesShardDiscovery = args.some((argument) => argument === '--update-shards' || argument.startsWith('--shard'));
    const parallelArgs = args.includes('--parallel')
        ? [
              ...(args.some((argument) => argument.startsWith('--processes')) ? [] : [`--processes=${processCount}`]),
              ...(usesShardDiscovery || args.some((argument) => argument.startsWith('--passthru-php'))
                  ? []
                  : [`--passthru-php='-d' 'memory_limit=${phpMemoryLimit}'`]),
          ]
        : [];

    run('docker', [
        ...composeArgs,
        'exec',
        '-T',
        ...(workspace ? ['-w', pestWorkspace] : []),
        ...(workspace ? ['-e', `PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:${pestWorkspace}/storage/framework/testing/php-ini`] : []),
        ...(coverage ? ['-e', 'XDEBUG_MODE=coverage'] : []),
        ...(jsonLdReportToken ? ['-e', `ERNIE_JSONLD_REPORT_TOKEN=${jsonLdReportToken}`] : []),
        'app',
        'php',
        '-d',
        `memory_limit=${phpMemoryLimit}`,
        './vendor/bin/pest',
        ...args,
        ...parallelArgs,
    ]);
}

function runPhase(name, args, reportName) {
    const startedAt = performance.now();
    console.log(`\n[pest] ${name}`);
    const junitPath = `${pestWorkspace}/storage/logs/pest-${reportName}.xml`;
    let testFailure;
    try {
        timings.measure(name, () => dockerPest([
            ...args,
            ...profileArgs,
            ...(reportDirectory ? [`--log-junit=${junitPath}`] : []),
        ], { workspace: true }));
    } catch (error) {
        testFailure = error;
    }
    console.log(`[pest] ${name} ${testFailure ? 'failed' : 'completed'} in ${((performance.now() - startedAt) / 1000).toFixed(1)}s`);

    if (reportDirectory) {
        const directory = resolve(reportDirectory);
        mkdirSync(directory, { recursive: true });
        try {
            run('docker', [...composeArgs, 'cp', `app:${junitPath}`, join(directory, `pest-${reportName}.xml`)]);
        } catch (error) {
            if (!testFailure) {
                throw error;
            }
            console.error(`[pest] Failed to save JUnit report: ${error.message}`);
        }
    }

    if (testFailure) {
        throw testFailure;
    }
}

let failure;
try {
    timings.measure('Backend services', () => run('docker', [...composeArgs, 'up', '-d', '--wait', 'db', 'redis', 'app']));
    timings.measure('Verify PHP dependencies', () => run('docker', [
        ...composeArgs, 'exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs',
    ]));

    if (!completeSuite) {
        if (nativeWorkspace) {
            timings.measure('Workspace preparation', () => run('docker', [
                ...composeArgs, 'exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh',
                ...(jsonLdReportToken ? [pestWorkspace] : []),
            ]));
        }
        timings.measure('Focused tests', () => dockerPest(
            [...(usesCoverageDriver ? [] : ['--no-coverage']), ...pestArgs],
            { coverage: usesCoverageDriver, workspace: nativeWorkspace },
        ));
    } else {
        const suiteStartedAt = performance.now();
        console.log(`[pest] Preparing a container-local workspace; PHP memory=${phpMemoryLimit}, parallel workers=${processCount}.`);
        timings.measure('Workspace preparation', () => run('docker', [
            ...composeArgs, 'exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh',
        ]));

        runPhase('Serial tests', ['--group=serial', '--exclude-testsuite=Arch', '--no-coverage'], 'serial');
        runPhase('Architecture tests', ['--testsuite=Arch', '--no-coverage'], 'architecture');
        runPhase('Parallel Unit and Feature tests', [
            '--parallel', '--no-progress', '--exclude-group=serial', '--exclude-testsuite=Arch', '--no-coverage',
            ...(updateShardTimings ? ['--update-shards'] : []),
        ], 'parallel');
        if (updateShardTimings) {
            mkdirSync('tests/.pest', { recursive: true });
            run('docker', [...composeArgs, 'cp', `app:${pestWorkspace}/tests/.pest/shards.json`, 'tests/.pest/shards.json']);
        }
        console.log(`[pest] Complete suite finished in ${((performance.now() - suiteStartedAt) / 1000).toFixed(1)}s.`);
    }
} catch (error) {
    failure = error;
    console.error(`[pest] ${error.message}`);
    process.exitCode = error.exitCode ?? 1;
} finally {
    timings.finish(process.exitCode ?? 0);
}

if (failure?.signal) {
    process.kill(process.pid, failure.signal);
}
