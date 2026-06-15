import { spawnSync } from 'node:child_process';

const composeArgs = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];

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
run('docker', [...composeArgs, 'exec', '-T', 'app', 'php', './vendor/bin/pest', '--no-coverage', ...process.argv.slice(2)]);
