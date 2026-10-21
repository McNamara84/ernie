import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';
import test from 'node:test';

// Frontend validation also runs on Windows and macOS hosts without Docker's shell tools.
const linuxOnly = { skip: process.platform !== 'linux' && 'Requires Linux shell tools (sh and sha256sum)' };

const dockerfile = readFileSync(new URL('../../Dockerfile.dev', import.meta.url), 'utf8');
const version = dockerfile.match(/^ARG XDEBUG_VERSION=(.+)$/mu)[1];
const primaryUrl = `https://xdebug.org/files/xdebug-${version}.tgz`;
const mirrorUrl = `https://raw.githubusercontent.com/xdebug/xdebug.org/master/html/files/xdebug-${version}.tgz`;
// Execute the Dockerfile's download and verification commands, stopping before compilation.
const download = dockerfile.replaceAll(/\\\r?\n/gu, '').split('\n')
    .find((line) => line.startsWith('RUN ') && line.includes('docker-php-ext-install xdebug;'))
    .slice(4).split('mkdir -p /usr/src/php/ext/xdebug')[0];
const archive = 'verified release archive';
const checksum = createHash('sha256').update(archive).digest('hex');

function runDownload(t, { primaryExit = 0, mirrorExit = 0, primaryBody = archive, mirrorBody = archive } = {}) {
    const directory = mkdtempSync(join(tmpdir(), 'ernie-xdebug-download-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    const output = join(directory, 'xdebug.tgz');
    const requests = join(directory, 'requests');
    // Simulate transport failures (including partial files); sha256sum remains real.
    writeFileSync(join(directory, 'curl'), `#!/bin/sh
set -eu
while [ "$#" -gt 0 ]; do
    case "$1" in
        https://*) url="$1" ;;
        -o) shift; output="$1" ;;
    esac
    shift
done
printf '%s\\n' "$url" >> "$TEST_REQUESTS"
case "$url" in
    https://xdebug.org/*) printf '%s' "$TEST_PRIMARY_BODY" > "$output"; exit "$TEST_PRIMARY_EXIT" ;;
    https://raw.githubusercontent.com/xdebug/xdebug.org/*) printf '%s' "$TEST_MIRROR_BODY" > "$output"; exit "$TEST_MIRROR_EXIT" ;;
    *) exit 99 ;;
esac
`, { mode: 0o755 });
    const result = spawnSync('sh', ['-c', download.replaceAll('/tmp/xdebug.tgz', '"$TEST_OUTPUT"')], {
        encoding: 'utf8',
        env: {
            ...process.env,
            PATH: `${directory}:${process.env.PATH}`,
            XDEBUG_VERSION: version,
            XDEBUG_SHA256: checksum,
            TEST_OUTPUT: output,
            TEST_REQUESTS: requests,
            TEST_PRIMARY_EXIT: String(primaryExit),
            TEST_MIRROR_EXIT: String(mirrorExit),
            TEST_PRIMARY_BODY: primaryBody,
            TEST_MIRROR_BODY: mirrorBody,
        },
    });
    assert.ifError(result.error);
    return { ...result, requests: readFileSync(requests, 'utf8').trim().split('\n'), archive: readFileSync(output, 'utf8') };
}

test('Xdebug download verifies the primary archive without contacting the mirror', linuxOnly, (t) => {
    const result = runDownload(t);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(result.requests, [primaryUrl]);
    assert.match(result.stdout, /xdebug\.tgz: OK/u);
});

test('Xdebug download falls back after connection refusal and replaces the partial download', linuxOnly, (t) => {
    const result = runDownload(t, { primaryExit: 7, primaryBody: 'incomplete download' });
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(result.requests, [primaryUrl, mirrorUrl]);
    assert.equal(result.archive, archive);
    assert.match(result.stdout, /xdebug\.tgz: OK/u);
});

test('Xdebug download fails when both sources are unavailable', linuxOnly, (t) => {
    const result = runDownload(t, { primaryExit: 7, mirrorExit: 22 });
    assert.notEqual(result.status, 0);
    assert.deepEqual(result.requests, [primaryUrl, mirrorUrl]);
    assert.doesNotMatch(result.stdout, /xdebug\.tgz: OK/u);
});

test('Xdebug download rejects a corrupt primary archive without falling back', linuxOnly, (t) => {
    const result = runDownload(t, { primaryBody: 'corrupt archive' });
    assert.notEqual(result.status, 0);
    assert.deepEqual(result.requests, [primaryUrl]);
    assert.match(result.stdout, /xdebug\.tgz: FAILED/u);
});

test('Xdebug download also rejects a corrupt mirror archive', linuxOnly, (t) => {
    const result = runDownload(t, { primaryExit: 7, mirrorBody: 'corrupt archive' });
    assert.notEqual(result.status, 0);
    assert.deepEqual(result.requests, [primaryUrl, mirrorUrl]);
    assert.match(result.stdout, /xdebug\.tgz: FAILED/u);
});
