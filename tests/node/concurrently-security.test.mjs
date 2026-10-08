import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import process from 'node:process';
import { test } from 'node:test';

// Resolve the dependency used by concurrently, including nested installations.
const requireFromConcurrently = createRequire(import.meta.resolve('concurrently'));
const { parse, quote } = requireFromConcurrently('shell-quote');

test('concurrently quoting rejects line terminators after comment tokens', () => {
    // GHSA-pqg4-j6r4-53mv: a later line terminator must not escape the comment.
    for (const terminator of ['\n', '\r', '\u2028', '\u2029']) {
        assert.throws(() => quote([
            'echo', 'safe', { comment: 'ignored' }, `value${terminator}echo unexpected`,
        ]), TypeError);
    }
});

test('concurrently quoting preserves ordinary command arguments', () => {
    const argumentsToPreserve = [
        'node', 'script with spaces.js', 'two words', '', "single'quote", 'double"quote',
        'https://example.test/#fragment', '$literal', 'semi;colon',
    ];
    assert.deepEqual(parse(quote(argumentsToPreserve)), argumentsToPreserve);
});

test('concurrently still launches and completes multiple commands', () => {
    const cli = join(dirname(requireFromConcurrently.resolve('concurrently/package.json')), 'dist/bin/index.js');
    const output = execFileSync(process.execPath, [
        cli, '--raw', 'node -p "6 * 7"', 'node -p "7 * 8"',
    ], { encoding: 'utf8' });
    assert.deepEqual(output.trim().split(/\r?\n/u).sort(), ['42', '56']);
});
