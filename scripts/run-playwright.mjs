import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);
const disabledWarningFlag = '--disable-warning=DEP0205';
const playwrightPackagePath = require.resolve('playwright/package.json');
const playwrightCliPath = join(dirname(playwrightPackagePath), 'cli.js');

function withDisabledPlaywrightLoaderWarning(nodeOptions = '') {
    const options = nodeOptions.trim();

    if (options.includes(disabledWarningFlag)) {
        return options;
    }

    return options ? `${options} ${disabledWarningFlag}` : disabledWarningFlag;
}

const result = spawnSync(process.execPath, [playwrightCliPath, ...process.argv.slice(2)], {
    env: {
        ...process.env,
        NODE_OPTIONS: withDisabledPlaywrightLoaderWarning(process.env.NODE_OPTIONS),
    },
    stdio: 'inherit',
});

if (result.error) {
    throw result.error;
}

if (result.signal) {
    process.kill(process.pid, result.signal);
}

process.exit(result.status ?? 1);
