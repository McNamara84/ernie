import { pathToFileURL } from 'node:url';

import { runCompose } from './docker-compose.mjs';

export function runPhpstan(args, execute = runCompose) {
    execute(['up', '-d', '--wait', 'db', 'redis', 'app']);
    execute(['exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs']);
    execute(['exec', '-T', 'app', 'sh', '/var/www/html/scripts/prepare-pest-workspace.sh']);
    execute(['exec', '-T', '-w', '/var/www/pest-workspace',
        '-e', 'PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/var/www/pest-workspace/storage/framework/testing/php-ini',
        'app', 'php', '-d', 'memory_limit=2G', './vendor/bin/phpstan',
        'analyse', '--memory-limit=2G', ...args]);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try { runPhpstan(process.argv.slice(2)); }
    catch (error) { console.error(error.message); process.exitCode = error.exitCode ?? 1; }
}
