import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

import { resolveMysqlSlices, runMysqlTests } from '../../scripts/run-mysql-tests.mjs';

function recorder(version = '9.7.2', failSlice) {
    const calls = [];
    return {
        calls,
        execute(args) {
            calls.push(args);
            if (args.includes('SELECT VERSION()')) return version;
            if (failSlice && args.includes(failSlice)) throw new Error('test failed');
        },
    };
}

test('complete MySQL inventory retains 25 reset boundaries and every existing target', () => {
    const slices = resolveMysqlSlices();
    assert.equal(slices.length, 25);
    assert.equal(new Set(slices.map(({ name }) => name)).size, 25);
    for (const { paths } of slices) for (const path of paths) assert.ok(existsSync(path), path);
    assert.deepEqual(slices.at(-1), resolveMysqlSlices('oai-pmh').at(-1));
    assert.equal(resolveMysqlSlices('tombstones').length, 1);
    assert.equal(resolveMysqlSlices('tombstones')[0].paths.length, 2);
});

test('refuses unknown slices, parallel workers, and incompatible servers before any reset', () => {
    for (const options of [{ selection: 'unknown' }, ...['--parallel', '-p', '--parallel=true', '--processes=2'].map((arg) => ({ pestArgs: [arg] }))]) {
        const recording = recorder();
        assert.throws(() => runMysqlTests({ ...options, execute: recording.execute }));
        assert.deepEqual(recording.calls, []);
    }
    for (const version of ['8.4.0', '9.6.0', '', '9.7.2\n8.4.0']) {
        const recording = recorder(version);
        assert.throws(() => runMysqlTests({ execute: recording.execute }), /pinned MySQL 9.7/u);
        assert.ok(recording.calls.every((args) => !args.some((arg) => arg.includes('DROP DATABASE'))));
    }
});

test('starts services once and preserves isolated reset-before-test ordering and memory', () => {
    const recording = recorder();
    runMysqlTests({ execute: recording.execute });
    assert.equal(recording.calls.filter((args) => args[0] === 'up').length, 1);
    const tests = recording.calls.filter((args) => args.includes('./vendor/bin/pest'));
    assert.equal(tests.length, 25);
    for (const args of tests) {
        const reset = recording.calls[recording.calls.indexOf(args) - 1];
        assert.ok(reset.at(-1).startsWith('DROP DATABASE IF EXISTS ernie_test; CREATE DATABASE ernie_test;'));
        assert.ok(reset.includes('db-test'));
        assert.ok(args.includes('ERNIE_TEST_DB_DATABASE=ernie_test'));
        assert.ok(args.includes('ERNIE_TEST_DB_HOST=db-test'));
        assert.ok(args.includes('memory_limit=2G'));
        assert.ok(args.includes('/var/www/pest-workspace'));
    }
});

test('stops on a failed test without resetting the next slice', () => {
    const recording = recorder('9.7.2', resolveMysqlSlices()[0].paths[0]);
    assert.throws(() => runMysqlTests({ execute: recording.execute }), /test failed/u);
    assert.equal(recording.calls.filter((args) => args.some((arg) => arg.startsWith('DROP DATABASE'))).length, 1);
});

test('prepare preserves data, reset only addresses the test server, and exec does not reset', () => {
    const prepare = recorder();
    runMysqlTests({ action: 'prepare', execute: prepare.execute });
    assert.ok(prepare.calls.at(-1).includes('db-test'));
    assert.ok(prepare.calls.at(-1).at(-1).startsWith('CREATE DATABASE IF NOT EXISTS ernie_test;'));
    assert.ok(!prepare.calls.at(-1).at(-1).includes('DROP'));

    const reset = recorder();
    runMysqlTests({ action: 'reset', execute: reset.execute });
    assert.ok(reset.calls.at(-1).includes('db-test'));
    assert.ok(reset.calls.at(-1).at(-1).startsWith('DROP DATABASE IF EXISTS ernie_test;'));

    const execute = recorder();
    runMysqlTests({ action: 'exec', pestArgs: ['tests/pest/Feature/Database/EditorSettingsMigrationTest.php'], execute: execute.execute });
    assert.ok(execute.calls.at(-1).includes('./vendor/bin/pest'));
    assert.ok(execute.calls.every((args) => !args.some((arg) => arg.includes('DROP DATABASE'))));
});
