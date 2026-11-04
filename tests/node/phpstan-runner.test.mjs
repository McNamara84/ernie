import assert from 'node:assert/strict';
import test from 'node:test';

import { runPhpstan } from '../../scripts/run-phpstan.mjs';

test('PHPStan verifies dependencies and refreshes the native workspace before analysis with inherited memory limits', () => {
    const calls = [];
    runPhpstan(['--configuration=phpstan.php86.neon', '--error-format=json'], (args) => calls.push(args));
    assert.ok(calls[1].includes('scripts/verify-php-dependencies.mjs'));
    assert.ok(calls[2].includes('/var/www/html/scripts/prepare-pest-workspace.sh'));
    const analysis = calls[3];
    assert.ok(analysis.includes('/var/www/pest-workspace'));
    assert.ok(analysis.some((arg) => arg.startsWith('PHP_INI_SCAN_DIR=') && arg.endsWith('/php-ini')));
    assert.ok(analysis.includes('memory_limit=2G'));
    assert.ok(analysis.includes('--memory-limit=2G'));
    assert.deepEqual(analysis.slice(-2), ['--configuration=phpstan.php86.neon', '--error-format=json']);
});

test('PHPStan does not analyze a stale workspace after preparation fails', () => {
    const calls = [];
    assert.throws(() => runPhpstan([], (args) => {
        calls.push(args);
        if (args.includes('/var/www/html/scripts/prepare-pest-workspace.sh')) throw new Error('copy failed');
    }), /copy failed/);
    assert.ok(calls.every((args) => !args.includes('./vendor/bin/phpstan')));
});
