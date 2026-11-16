import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { test } from 'node:test';

import { verifyVitestFiles } from '../../scripts/verify-vitest-files.mjs';
import { resolveAffectedArguments, syncWayfinderFiles, wayfinderCacheMatches, wayfinderFileHashes } from '../../scripts/vitest-runtime.mjs';

test('Wayfinder synchronization preserves unchanged mtimes and updates changed nested files', () => {
    const tempRoot = resolve(tmpdir());
    const directory = mkdtempSync(join(tempRoot, 'ernie-vitest-sync-'));
    assert.ok(directory.startsWith(`${tempRoot}\\`) || directory.startsWith(`${tempRoot}/`));
    try {
        const source = join(directory, 'source');
        const destination = join(directory, 'destination');
        mkdirSync(join(source, 'routes'), { recursive: true });
        writeFileSync(join(source, 'routes', 'index.ts'), 'first');
        assert.deepEqual(syncWayfinderFiles(source, destination), [join('routes', 'index.ts')]);
        const target = join(destination, 'routes', 'index.ts');
        utimesSync(target, new Date(2020, 0, 1), new Date(2020, 0, 1));
        const timestamp = statSync(target).mtimeMs;
        assert.deepEqual(syncWayfinderFiles(source, destination), []);
        assert.equal(statSync(target).mtimeMs, timestamp);
        writeFileSync(join(source, 'routes', 'index.ts'), 'second');
        syncWayfinderFiles(source, destination);
        assert.equal(readFileSync(target, 'utf8'), 'second');
        assert.notEqual(statSync(target).mtimeMs, timestamp);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});

test('the first affected run or a changed configuration forces a complete dependency-recording run', () => {
    for (const previous of [undefined, 'old']) {
        assert.deepEqual(resolveAffectedArguments(['run', '--since-last-run'], 'current', previous, false), {
            args: ['run', '--experimental.recordDependencies'],
            fullRun: true,
        });
    }
});

test('matching fingerprints retain incremental selection and focused runs cannot certify the whole suite', () => {
    const args = ['run', '--since-last-run'];
    assert.deepEqual(resolveAffectedArguments(args, 'same', 'same', false), {
        args: [...args, '--experimental.recordDependencies'],
        fullRun: false,
    });
    assert.equal(resolveAffectedArguments([...args, 'one.test.ts'], 'new', 'old', false).fullRun, false);
    assert.equal(resolveAffectedArguments(['run'], 'new', 'old', false).fullRun, false);
    assert.equal(resolveAffectedArguments(['run', '--project', 'browser'], 'new', 'old', false).fullRun, false);
});

test('an ordinary complete run cannot certify a dependency-recording baseline', () => {
    for (const previous of [undefined, 'old', 'current']) {
        assert.deepEqual(resolveAffectedArguments(['run'], 'current', previous, false), { args: ['run'], fullRun: false });
        assert.deepEqual(resolveAffectedArguments(['run', '--coverage'], 'current', previous, false), {
            args: ['run', '--coverage'],
            fullRun: false,
        });
    }
});

test('only an unfiltered complete recording run can certify the baseline', () => {
    const args = ['run', '--experimental.recordDependencies'];
    assert.deepEqual(resolveAffectedArguments(args, 'current', undefined, false), { args, fullRun: true });
    assert.equal(resolveAffectedArguments([...args, '--coverage'], 'current', undefined, false).fullRun, true);
    assert.equal(resolveAffectedArguments(['run', '--experimental.recordDependencies=true'], 'current', undefined, false).fullRun, true);
    assert.equal(resolveAffectedArguments(['--experimental.recordDependencies'], 'current', undefined, false).fullRun, false);
    assert.equal(resolveAffectedArguments(['--since-last-run'], 'current', undefined, false).fullRun, false);
    for (const filter of [['one.test.ts'], ['run'], ['--project', 'browser'], ['-t', 'one case'], ['--shard=1/3'], ['--last-failed']]) {
        assert.equal(resolveAffectedArguments([...args, ...filter], 'current', undefined, false).fullRun, false);
    }
});

test('affected runs retain recording without duplicating an explicit recording flag', () => {
    for (const recordingFlag of ['--experimental.recordDependencies', '--experimental.recordDependencies=true']) {
        const args = ['run', '--since-last-run', recordingFlag];
        assert.deepEqual(resolveAffectedArguments(args, 'same', 'same', false), { args, fullRun: false });
        assert.deepEqual(resolveAffectedArguments(args, 'new', 'old', false), {
            args: ['run', recordingFlag],
            fullRun: true,
        });
    }
    assert.throws(
        () => resolveAffectedArguments(['run', '--since-last-run', '--experimental.recordDependencies=false'], 'same', 'same', false),
        /requires dependency recording/,
    );
});

test('CI rejects the local incremental shortcut instead of silently narrowing validation', () => {
    assert.throws(() => resolveAffectedArguments(['run', '--since-last-run'], 'same', 'same', true), /complete suite/);
});

test('complete coverage results require both projects exactly once', () => {
    const files = ['tests/vitest/unit.test.ts', 'tests/vitest-browser/control.test.tsx'];
    assert.deepEqual(verifyVitestFiles(files, [...files].reverse()), { jsdom: 1, browser: 1 });
    assert.throws(() => verifyVitestFiles(files, files.slice(0, 1)), /complete configured/);
    assert.throws(() => verifyVitestFiles(files, [...files, files[0]]), /Duplicate/);
    assert.throws(() => verifyVitestFiles(files, [...files, 'tests/vitest/stale.test.ts']), /complete configured/);
});

test('route cache detects edits and missing output, and obsolete generated files are removed safely', () => {
    const tempRoot = resolve(tmpdir());
    const directory = mkdtempSync(join(tempRoot, 'ernie-vitest-cache-'));
    assert.ok(directory.startsWith(`${tempRoot}\\`) || directory.startsWith(`${tempRoot}/`));
    try {
        const source = join(directory, 'source');
        const destination = join(directory, 'destination');
        mkdirSync(source);
        writeFileSync(join(source, 'route.ts'), 'generated');
        const files = wayfinderFileHashes(source);
        syncWayfinderFiles(source, destination);
        const manifest = { fingerprint: 'current', files };
        assert.equal(wayfinderCacheMatches(manifest, 'current', destination), true);
        assert.equal(wayfinderCacheMatches(manifest, 'changed', destination), false);
        writeFileSync(join(destination, 'route.ts'), 'local edits');
        assert.equal(wayfinderCacheMatches(manifest, 'current', destination), false);
        rmSync(join(source, 'route.ts'));
        assert.throws(() => syncWayfinderFiles(source, destination, files), /local edits/);
        assert.equal(readFileSync(join(destination, 'route.ts'), 'utf8'), 'local edits');
        writeFileSync(join(destination, 'route.ts'), 'generated');
        syncWayfinderFiles(source, destination, files);
        assert.equal(wayfinderCacheMatches(manifest, 'current', destination), false);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
