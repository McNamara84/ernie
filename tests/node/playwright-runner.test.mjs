import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import process from 'node:process';
import test from 'node:test';

import { runPlaywrightDevstack as runDevstack } from '../../scripts/run-playwright-devstack.mjs';

function runPlaywrightDevstack(args, options) {
    if (options.rootDirectory) return runDevstack(args, options);
    const temporaryRoot = resolve(tmpdir());
    const directory = mkdtempSync(join(temporaryRoot, 'ernie-browser-routing-'));
    try {
        return runDevstack(args, { ...options, rootDirectory: directory });
    } finally {
        assert.ok(resolve(directory).startsWith(`${temporaryRoot}${sep}`));
        rmSync(directory, { recursive: true });
    }
}

test('browser validation verifies synchronous log mail and fake DataCite before tests, then restores the app', () => {
    const calls = [];
    runPlaywrightDevstack(['--project=chromium'], { execute: (command, args) => calls.push([command, args]) });
    assert.equal(calls.length, 9);
    assert.ok(calls[0][1].includes('scripts/verify-vite-dependencies.mjs'));
    assert.ok(calls[1][1].includes('scripts/verify-php-dependencies.mjs'));
    assert.ok(calls[2][1].includes('docker-compose.playwright-test.yml'));
    assert.deepEqual(calls[3][1].slice(-3), ['nginx', '-s', 'reload']);
    assert.ok(calls[4][1].includes('memory_limit=2G'));
    assert.ok(calls[4][1].at(-1).includes('FakeDataCiteRegistrationService'));
    assert.ok(calls[4][1].at(-1).includes('DataPublicationTeamRecipientService'));
    assert.ok(calls[5][1].includes('scripts/wait-for-playwright-backend.mjs'));
    assert.ok(calls[6][1].includes('--project=chromium'));
    assert.ok(!calls[7][1].includes('docker-compose.playwright-test.yml'));
    assert.deepEqual(calls[7][1].slice(-5), ['up', '-d', '--wait', 'app', 'webserver']);
    assert.deepEqual(calls[8][1].slice(-3), ['nginx', '-s', 'reload']);
});

test('failed browser execution and failed setup both restore the development backend', () => {
    for (const failedCall of [3, 4, 5, 6, 7]) {
        const calls = [];
        assert.throws(
            () =>
                runPlaywrightDevstack([], {
                    execute(command, args) {
                        calls.push([command, args]);
                        if (calls.length === failedCall) throw new Error('validation failed');
                    },
                }),
            /validation failed/u,
        );
        assert.equal(calls.at(-1)[0], 'docker');
        assert.ok(!calls.at(-1)[1].includes('docker-compose.playwright-test.yml'));
        assert.deepEqual(calls.at(-2)[1].slice(-5), ['up', '-d', '--wait', 'app', 'webserver']);
        assert.deepEqual(calls.at(-1)[1].slice(-3), ['nginx', '-s', 'reload']);
    }
});

test('invalid frontend dependencies fail before changing the development backend', () => {
    const calls = [];
    assert.throws(
        () =>
            runPlaywrightDevstack([], {
                execute(command, args) {
                    calls.push([command, args]);
                    throw new Error('invalid dependencies');
                },
            }),
        /invalid dependencies/u,
    );
    assert.equal(calls.length, 1);
    assert.ok(calls[0][1].includes('scripts/verify-vite-dependencies.mjs'));
    assert.ok(!calls[0][1].includes('up'));
});

test('stale PHP dependencies fail before changing the development backend', () => {
    const calls = [];
    assert.throws(
        () =>
            runPlaywrightDevstack([], {
                execute(command, args) {
                    calls.push([command, args]);
                    if (calls.length === 2) throw new Error('stale PHP dependencies');
                },
            }),
        /stale PHP dependencies/u,
    );
    assert.equal(calls.length, 2);
    assert.ok(calls.at(-1)[1].includes('scripts/verify-php-dependencies.mjs'));
    assert.ok(calls.every(([, args]) => !args.includes('up')));
});

test('listing tests leaves backend services untouched', () => {
    const calls = [];
    runPlaywrightDevstack(['--list'], { execute: (command, args) => calls.push([command, args]) });
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], process.execPath);
});

test('built assets restore the original Vite marker after success and failures', () => {
    for (const failedPhase of [undefined, 'prepare-pest-workspace.sh', 'build-playwright-assets.sh', 'cp', 'browser']) {
        const temporaryRoot = resolve(tmpdir());
        const directory = mkdtempSync(join(temporaryRoot, 'ernie-browser-assets-'));
        mkdirSync(join(directory, 'public'));
        const hotPath = join(directory, 'public', 'hot');
        const marker = Buffer.from('https://ernie.localhost:5173\r\n');
        writeFileSync(hotPath, marker);
        const calls = [];
        try {
            const validate = () =>
                runPlaywrightDevstack([], {
                    assets: 'build',
                    rootDirectory: directory,
                    execute(command, args) {
                        calls.push([command, args]);
                        if (command === process.execPath) {
                            assert.equal(existsSync(hotPath), false);
                            if (failedPhase === 'browser' && args.includes('test')) throw new Error('browser failed');
                        } else if (failedPhase && args.some((argument) => argument.endsWith(failedPhase))) {
                            throw new Error(`${failedPhase} failed`);
                        }
                    },
                });
            if (failedPhase) assert.throws(validate, /failed/u);
            else validate();
            assert.deepEqual(readFileSync(hotPath), marker);
            assert.deepEqual(calls.at(-2)[1].slice(-5), ['up', '-d', '--wait', 'app', 'webserver']);
            assert.deepEqual(calls.at(-1)[1].slice(-3), ['nginx', '-s', 'reload']);
            assert.equal(existsSync(join(directory, 'storage/framework/testing/playwright-nginx.conf')), false);
        } finally {
            assert.ok(resolve(directory).startsWith(`${temporaryRoot}${sep}`));
            rmSync(directory, { recursive: true });
        }
    }
});

test('invalid asset selection fails before changing the development backend', () => {
    const calls = [];
    assert.throws(() => runPlaywrightDevstack([], { assets: 'unknown', execute: (...call) => calls.push(call) }), /must be vite or build/u);
    assert.equal(calls.length, 0);
});

test('an existing routing file is preserved and prevents concurrent backend changes', () => {
    const temporaryRoot = resolve(tmpdir());
    const directory = mkdtempSync(join(temporaryRoot, 'ernie-browser-existing-routing-'));
    const routingPath = join(directory, 'storage/framework/testing/playwright-nginx.conf');
    const calls = [];
    mkdirSync(join(directory, 'storage/framework/testing'), { recursive: true });
    writeFileSync(routingPath, 'existing owner');
    try {
        assert.throws(() => runDevstack([], { rootDirectory: directory, execute: (...call) => calls.push(call) }), /already exists/u);
        assert.equal(readFileSync(routingPath, 'utf8'), 'existing owner');
        assert.equal(calls.length, 2);
        assert.ok(calls.every(([, args]) => !args.includes('up')));
    } finally {
        assert.ok(resolve(directory).startsWith(`${temporaryRoot}${sep}`));
        rmSync(directory, { recursive: true });
    }
});

test('failed service restoration keeps its mounted routing file available for recovery', () => {
    const temporaryRoot = resolve(tmpdir());
    const directory = mkdtempSync(join(temporaryRoot, 'ernie-browser-routing-recovery-'));
    try {
        assert.throws(
            () =>
                runDevstack([], {
                    rootDirectory: directory,
                    execute(command, args) {
                        if (command === 'docker' && args.includes('up') && !args.includes('docker-compose.playwright-test.yml'))
                            throw new Error('restore failed');
                    },
                }),
            /restore failed/u,
        );
        assert.equal(existsSync(join(directory, 'storage/framework/testing/playwright-nginx.conf')), true);
    } finally {
        assert.ok(resolve(directory).startsWith(`${temporaryRoot}${sep}`));
        rmSync(directory, { recursive: true });
    }
});
