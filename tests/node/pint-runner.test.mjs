import assert from 'node:assert/strict';
import test from 'node:test';

import { runPintCheck } from '../../scripts/run-pint-check.mjs';

test('Pint checks use a freshly prepared native workspace, remain non-mutating, and keep 2 GB', () => {
    const calls = [];
    runPintCheck(['tests/Pest.php'], { execute: (args) => calls.push(args) });
    assert.equal(calls.length, 4);
    assert.ok(calls[1].includes('scripts/verify-php-dependencies.mjs'));
    assert.ok(calls[2].includes('/var/www/html/scripts/prepare-pest-workspace.sh'));
    assert.ok(calls[3].includes('/var/www/pest-workspace'));
    assert.ok(calls[3].includes('memory_limit=2G'));
    assert.ok(calls[3].includes('tests/Pest.php'));
    assert.deepEqual(calls[3].slice(-2), ['--test', '--parallel']);
});

test('a failed workspace preparation prevents checking an old copy', () => {
    const calls = [];
    assert.throws(() => runPintCheck([], { execute(args) {
        calls.push(args);
        if (calls.length === 3) throw new Error('copy failed');
    } }), /copy failed/u);
    assert.equal(calls.length, 3);
});
