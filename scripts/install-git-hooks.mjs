import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const current = spawnSync('git', ['config', '--get', 'core.hooksPath'], { cwd: root, encoding: 'utf8' });
if (current.error || (current.status !== 0 && current.status !== 1)) {
    console.error(current.stderr || current.error?.message || 'Could not read the local Git hooks path.');
    process.exit(1);
}

const configuredPath = current.stdout.trim();
if (configuredPath && resolve(root, configuredPath) !== resolve(root, '.githooks')) {
    console.error(`An existing Git hooks path is configured: ${configuredPath}`);
    console.error('Integrate .githooks/pre-commit with that hook before changing core.hooksPath.');
    process.exit(1);
}

const defaultHook = spawnSync('git', ['rev-parse', '--git-path', 'hooks/pre-commit'], { cwd: root, encoding: 'utf8' });
if (defaultHook.error || defaultHook.status !== 0) {
    console.error(defaultHook.stderr || defaultHook.error?.message || 'Could not locate the default Git hook.');
    process.exit(1);
}
if (!configuredPath && existsSync(resolve(root, defaultHook.stdout.trim()))) {
    console.error('An active .git/hooks/pre-commit already exists. Integrate this hook manually before changing core.hooksPath.');
    process.exit(1);
}

const installed = spawnSync('git', ['config', '--local', 'core.hooksPath', '.githooks'], { cwd: root, stdio: 'inherit' });
if (installed.error || installed.status !== 0) {
    if (installed.error) console.error(installed.error.message);
    process.exit(installed.status || 1);
}

console.log('Git pre-commit hook installed for this checkout.');
