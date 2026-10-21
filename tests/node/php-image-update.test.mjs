import assert from 'node:assert/strict';
import test from 'node:test';

import { preparePhpImageUpdate } from '../../scripts/update-php-image.mjs';

function fixture() {
    const digest = `sha256:${'a'.repeat(64)}`;
    return {
        version: '8.5.12',
        release: { version: '8.5.12', date: '21 Oct 2026', source: [{ filename: 'php-8.5.12.tar.xz', sha256: 'b'.repeat(64) }] },
        manifest: { digest, manifests: ['amd64', 'arm64'].map((architecture) => ({ platform: { os: 'linux', architecture } })) },
        files: new Map([
            ['Dockerfile', `FROM mysql:8.4.11 AS legacy\nFROM php:8.5.11-fpm-trixie@${digest} AS app-base\n`],
            ['Dockerfile.dev', `FROM php:8.5.11-fpm-trixie@${digest} AS app\n`],
        ]),
    };
}

test('PHP update prepares the same immutable image for both Dockerfiles and preserves other stages', () => {
    const input = fixture();
    const before = new Map(input.files);
    const result = preparePhpImageUpdate(input);
    assert.match(result.changes.get('Dockerfile'), /^FROM mysql:8\.4\.11 AS legacy\n/u);
    for (const content of result.changes.values()) {
        assert.ok(content.includes(`FROM ${result.image} AS `));
    }
    assert.deepEqual(input.files, before);
});

test('PHP update rejects prereleases, branch changes, unconfirmed releases and downgrades', () => {
    for (const version of ['8.6.0', '8.5.12RC1', 'latest', '8.5.12;echo injected']) {
        assert.throws(() => preparePhpImageUpdate({ ...fixture(), version }), /exact stable/u);
    }
    assert.throws(() => preparePhpImageUpdate({ ...fixture(), release: { version: '8.5.11' } }), /not confirmed/u);
    const input = fixture();
    input.files.set('Dockerfile', input.files.get('Dockerfile').replace('8.5.11', '8.5.13'));
    assert.throws(() => preparePhpImageUpdate(input), /downgrade/u);
});

test('PHP update waits for both deployment architectures and a valid digest', () => {
    const input = fixture();
    input.manifest.manifests.pop();
    assert.throws(() => preparePhpImageUpdate(input), /linux\/arm64/u);
    assert.throws(() => preparePhpImageUpdate({ ...fixture(), manifest: { digest: 'latest' } }), /valid image digest/u);
});

test('PHP update rejects missing, duplicated or divergent PHP stages before producing changes', () => {
    for (const content of ['', fixture().files.get('Dockerfile.dev').repeat(2), fixture().files.get('Dockerfile.dev').replace('8.5.11', '8.5.10')]) {
        const input = fixture();
        input.files.set('Dockerfile.dev', content);
        assert.throws(() => preparePhpImageUpdate(input), /No files changed/u);
    }
});
