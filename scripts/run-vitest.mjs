import { spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const storageFile = '.tmp/node-localstorage-vitest.json';
const storageFlag = `--localstorage-file=${storageFile}`;

function withStorageFlag(nodeOptions = '') {
    const options = nodeOptions.trim();

    if (options.includes('--localstorage-file=')) {
        return options;
    }

    return options ? `${options} ${storageFlag}` : storageFlag;
}

if (!process.execArgv.some((arg) => arg.startsWith('--localstorage-file='))) {
    mkdirSync('.tmp', { recursive: true });

    const result = spawnSync(process.execPath, [storageFlag, fileURLToPath(import.meta.url), ...process.argv.slice(2)], {
        env: {
            ...process.env,
            NODE_OPTIONS: withStorageFlag(process.env.NODE_OPTIONS),
        },
        stdio: 'inherit',
    });

    if (result.signal) {
        process.kill(process.pid, result.signal);
    }

    process.exit(result.status ?? 1);
}

process.env.NODE_OPTIONS = withStorageFlag(process.env.NODE_OPTIONS);

await import('../node_modules/vitest/vitest.mjs');
