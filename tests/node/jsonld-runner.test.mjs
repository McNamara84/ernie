import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { dirname } from 'node:path';
import process from 'node:process';
import { test } from 'node:test';

import { runJsonLd } from '../../scripts/run-jsonld.mjs';

const token = 'a'.repeat(32);
const report = { actualPhpExport: true };
const counts = { dataCiteDocuments: 42, schemaOrgDocuments: 34 };

function transport(options = {}) {
    const calls = [];
    const paths = [];
    const defaults = {
        token,
        ci: false,
        run: (command, args, environment) => calls.push({ command, args, environment }),
        checkSnapshots: () => {},
        readReport: (path) => {
            paths.push(path);
            return report;
        },
        validateReport: (input) => {
            assert.equal(input, report);
            return counts;
        },
        removeReport: (path) => paths.push(path),
        log: () => {},
    };
    return { calls, paths, options: { ...defaults, ...options } };
}

test('local wrapper uses Pest, an isolated report handoff, and cleans its workspace', async () => {
    const fixture = transport();
    assert.deepEqual(await runJsonLd(fixture.options), counts);
    assert.equal(fixture.calls[0].command, process.execPath);
    assert.ok(fixture.calls[0].args.includes('scripts/run-pest.mjs'));
    assert.equal(fixture.calls[0].environment.ERNIE_JSONLD_REPORT_TOKEN, token);
    assert.ok(fixture.calls[1].args.includes(`app:/tmp/ernie-jsonld-${token}/storage/framework/testing/jsonld-${token}.json`));
    assert.equal(fixture.calls[1].args.at(-1), fixture.paths[0]);
    assert.deepEqual(fixture.calls[2].args.slice(-4), ['rm', '-rf', '--', `/tmp/ernie-jsonld-${token}`]);
    assert.equal(existsSync(dirname(fixture.paths[0])), false);
});

test('concurrent runs use separate reports and workspaces', async () => {
    const first = transport();
    const second = transport({ token: 'b'.repeat(32) });
    await Promise.all([runJsonLd(first.options), runJsonLd(second.options)]);
    assert.notEqual(first.paths[0], second.paths[0]);
    assert.notEqual(first.calls[1].args.at(-2), second.calls[1].args.at(-2));
    assert.notEqual(first.calls[2].args.at(-1), second.calls[2].args.at(-1));
});

test('hosted CI invokes PHP with 2 GB and avoids Docker', async () => {
    const fixture = transport({ ci: true });
    await runJsonLd(fixture.options);
    assert.equal(fixture.calls.length, 1);
    assert.equal(fixture.calls[0].command, 'php');
    assert.ok(fixture.calls[0].args.includes('memory_limit=2G'));
    assert.equal(fixture.paths[0], fixture.paths[1]);
});

test('invalid tokens and snapshots fail before invoking PHP or Docker', async () => {
    for (const invalid of ['../escape', '', 'a'.repeat(31), 'A'.repeat(32)]) {
        const fixture = transport({ token: invalid });
        await assert.rejects(runJsonLd(fixture.options), /Invalid JSON-LD report token/);
        assert.equal(fixture.calls.length, 0);
    }
    const fixture = transport({
        checkSnapshots: () => {
            throw new Error('Immutable snapshot mismatch');
        },
    });
    await assert.rejects(runJsonLd(fixture.options), /Immutable snapshot mismatch/);
    assert.equal(fixture.calls.length, 0);
});

test('PHP and copy failures propagate without validating a stale report', async () => {
    for (const failedCall of [0, 1]) {
        const failure = Object.assign(new Error('Transport failed'), { exitCode: 7 });
        const fixture = transport();
        const original = fixture.options.run;
        fixture.options.run = (...args) => {
            const index = fixture.calls.length;
            original(...args);
            if (index === failedCall) throw failure;
        };
        await assert.rejects(runJsonLd(fixture.options), (error) => error === failure && error.exitCode === 7);
        assert.equal(fixture.paths.length, 0);
        assert.equal(fixture.calls.at(-1).args.at(-1), `/tmp/ernie-jsonld-${token}`);
    }
});

test('semantic and unreadable-report failures still clean the workspace', async () => {
    for (const overrides of [
        {
            validateReport: () => {
                throw new Error('Lost metadata');
            },
        },
        {
            readReport: () => {
                throw new Error('Report missing');
            },
        },
    ]) {
        const fixture = transport(overrides);
        await assert.rejects(runJsonLd(fixture.options), /Lost metadata|Report missing/);
        assert.equal(fixture.calls.at(-1).args.at(-1), `/tmp/ernie-jsonld-${token}`);
    }
});

test('cleanup failure fails success but preserves an original test failure', async () => {
    for (const testFailure of [false, true]) {
        const fixture = transport({
            run: (_command, args) => {
                if (args.includes('exec')) throw new Error('Cleanup failed');
                if (testFailure) throw new Error('PHP failed');
            },
        });
        await assert.rejects(runJsonLd(fixture.options), testFailure ? /PHP failed/ : /Cleanup failed/);
    }
});
