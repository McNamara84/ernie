import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

// Every PHP wrapper must use the same stack, including nested JSON-LD runs.
export function composeArguments(environment = process.env) {
    if (environment.ERNIE_PHP86_COMPOSE?.trim()) {
        if (!/^ernie-php86-[a-f0-9]{16}$/.test(environment.COMPOSE_PROJECT_NAME ?? '')) {
            throw new Error('The PHP 8.6 stack requires an isolated project name.');
        }
        return ['compose', '--project-name', environment.COMPOSE_PROJECT_NAME,
            '--env-file', resolve(environment.ERNIE_PHP86_ENV_FILE), '-f', resolve(environment.ERNIE_PHP86_COMPOSE)];
    }
    return ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];
}

export function runCompose(args, options = {}) {
    const result = spawnSync('docker', [...composeArguments(), ...args], { stdio: 'inherit', ...options });
    if (result.error || result.signal || result.status !== 0) {
        const error = result.error ?? new Error(`Docker exited with ${result.signal ?? result.status ?? 1}.`);
        error.exitCode = result.status ?? 1;
        throw error;
    }
    return result;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try { runCompose(process.argv.slice(2)); }
    catch (error) { console.error(error.message); process.exitCode = error.exitCode ?? 1; }
}
