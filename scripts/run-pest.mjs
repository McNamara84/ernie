import { spawnSync } from 'node:child_process';

const composeArgs = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
const pestArgs = process.argv.slice(2);
const usesCoverageDriver = pestArgs.includes('--tia') || pestArgs.includes('--mutate');

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

run('docker', [...composeArgs, 'up', '-d', '--wait', 'db', 'redis', 'app']);
run('docker', [
    ...composeArgs,
    'exec',
    '-T',
    ...(usesCoverageDriver ? ['-e', 'XDEBUG_MODE=coverage'] : []),
    'app',
    'php',
    './vendor/bin/pest',
    ...(usesCoverageDriver ? [] : ['--no-coverage']),
    ...pestArgs,
]);
