import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const webStorageFlag = '--no-experimental-webstorage';

function withWebStorageFlag(nodeOptions = '') {
    const options = nodeOptions.trim();

    if (options.includes('--no-experimental-webstorage') || options.includes('--localstorage-file=')) {
        return options;
    }

    return options ? `${options} ${webStorageFlag}` : webStorageFlag;
}

if (!process.execArgv.includes(webStorageFlag)) {
    const result = spawnSync(process.execPath, [webStorageFlag, fileURLToPath(import.meta.url), ...process.argv.slice(2)], {
        env: {
            ...process.env,
            NODE_OPTIONS: withWebStorageFlag(process.env.NODE_OPTIONS),
        },
        stdio: 'inherit',
    });

    if (result.signal) {
        process.kill(process.pid, result.signal);
    }

    process.exit(result.status ?? 1);
}

process.env.NODE_OPTIONS = withWebStorageFlag(process.env.NODE_OPTIONS);

await import('../node_modules/vitest/vitest.mjs');
