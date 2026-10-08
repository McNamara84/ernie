import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { test } from 'node:test';

import { createTestTimings, resolveTestWorkers } from '../../scripts/test-runtime.mjs';

test('blank overrides use the bounded CPU default, while explicit overrides remain usable', () => {
    for (const override of [undefined, '', '  ']) {
        assert.equal(resolveTestWorkers(override, 16), 8);
        assert.equal(resolveTestWorkers(override, 1), 1);
        assert.equal(resolveTestWorkers(override, 7), 3);
        assert.equal(resolveTestWorkers(override, 64), 8);
    }

    assert.equal(resolveTestWorkers(' 4 ', 16), 4);
    assert.equal(resolveTestWorkers('12', 16), 12);

    for (const override of ['0', '-1', '1.5', 'NaN', 'eight']) {
        assert.throws(() => resolveTestWorkers(override, 16), /positive integer/);
    }
});

test('reports preserve failed phases and include startup time in total elapsed time', () => {
    let time = 0;
    const timings = createTestTimings('pest', { outputPath: '', now: () => time });
    timings.measure('services', () => { time = 20; });
    time = 25;

    assert.throws(() => timings.measure('tests', () => {
        time = 50;
        throw new Error('test failure');
    }), /test failure/);

    time = 55;
    const report = timings.finish(1);
    assert.equal(report.status, 'failed');
    assert.equal(report.exitCode, 1);
    assert.equal(report.durationMs, 55);
    assert.deepEqual(report.phases, [
        { name: 'services', status: 'passed', durationMs: 20 },
        { name: 'tests', status: 'failed', durationMs: 25 },
    ]);
});

test('timing files create their parent directory and record success and revision', () => {
    const temporaryRoot = resolve(tmpdir());
    const directory = mkdtempSync(join(temporaryRoot, 'ernie-test-timings-'));
    const outputPath = join(directory, 'reports', 'timings.json');

    try {
        const timings = createTestTimings('pest', { outputPath });
        timings.measure('tests', () => 42);
        timings.finish();
        const report = JSON.parse(readFileSync(outputPath, 'utf8'));
        assert.equal(report.status, 'passed');
        assert.equal(report.phases[0].name, 'tests');
        assert.match(report.commit, /^[a-f0-9]{40}$/);
    } finally {
        assert.ok(resolve(directory).startsWith(`${temporaryRoot}/`) || resolve(directory).startsWith(`${temporaryRoot}\\`));
        rmSync(directory, { recursive: true, force: true });
    }
});
