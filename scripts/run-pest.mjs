import { spawnSync } from 'node:child_process';
import { availableParallelism } from 'node:os';
import { performance } from 'node:perf_hooks';

const composeArgs = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
const pestArgs = process.argv.slice(2);
const phpMemoryLimit = '2G';
const pestWorkspace = '/var/www/pest-workspace';
const defaultProcessCount = Math.max(1, Math.min(8, Math.floor(availableParallelism() / 2)));
const configuredProcessCount = process.env.ERNIE_PEST_PROCESSES?.trim();
const processCount = configuredProcessCount === undefined ? defaultProcessCount : Number(configuredProcessCount);
const profileArgs = process.env.ERNIE_PEST_PROFILE === '1' ? ['--profile'] : [];

if (!Number.isSafeInteger(processCount) || processCount < 1) {
    console.error('ERNIE_PEST_PROCESSES must be a positive integer.');
    process.exit(1);
}

const usesCoverageDriver = pestArgs.some(
    (argument) => argument === '--tia' || argument === '--mutate' || argument.startsWith('--coverage'),
);

function run(command, args) {
    const result = spawnSync(command, args, {
        stdio: 'inherit',
    });

    if (result.error) {
        throw result.error;
    }

    if (result.signal) {
        process.kill(process.pid, result.signal);
    }

    if (result.status !== 0) {
        process.exit(result.status ?? 1);
    }
}

function dockerPest(args, { coverage = false, workspace = false } = {}) {
    const parallelArgs = args.includes('--parallel')
        ? [
              ...(args.some((argument) => argument.startsWith('--processes')) ? [] : [`--processes=${processCount}`]),
              ...(args.some((argument) => argument.startsWith('--passthru-php'))
                  ? []
                  : [`--passthru-php='-d' 'memory_limit=${phpMemoryLimit}'`]),
          ]
        : [];

    run('docker', [
        ...composeArgs,
        'exec',
        '-T',
        ...(workspace ? ['-w', pestWorkspace] : []),
        ...(coverage ? ['-e', 'XDEBUG_MODE=coverage'] : []),
        'app',
        'php',
        '-d',
        `memory_limit=${phpMemoryLimit}`,
        './vendor/bin/pest',
        ...args,
        ...parallelArgs,
    ]);
}

function runPhase(name, args) {
    const startedAt = performance.now();
    console.log(`\n[pest] ${name}`);
    dockerPest([...args, ...profileArgs], { workspace: true });
    console.log(`[pest] ${name} completed in ${((performance.now() - startedAt) / 1000).toFixed(1)}s`);
}

run('docker', [...composeArgs, 'up', '-d', '--wait', 'db', 'redis', 'app']);

if (pestArgs.length > 0) {
    dockerPest([...(usesCoverageDriver ? [] : ['--no-coverage']), ...pestArgs], { coverage: usesCoverageDriver });
    process.exit(0);
}

const suiteStartedAt = performance.now();

console.log(
    `[pest] Preparing a container-local workspace; PHP memory=${phpMemoryLimit}, parallel workers=${processCount}.`,
);
run('docker', [...composeArgs, 'exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh']);

runPhase('Serial tests', ['--group=serial', '--exclude-testsuite=Arch', '--no-coverage']);
runPhase('Architecture tests', ['--testsuite=Arch', '--no-coverage']);
runPhase('Parallel Unit and Feature tests', [
    '--parallel',
    '--no-progress',
    '--exclude-group=serial',
    '--exclude-testsuite=Arch',
    '--no-coverage',
]);

console.log(`[pest] Complete suite finished in ${((performance.now() - suiteStartedAt) / 1000).toFixed(1)}s.`);
