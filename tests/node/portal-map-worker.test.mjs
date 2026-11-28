import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { execPath } from 'node:process';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { resolveConfig } from 'vite';

test('a server build preserves the portal client assets and writes the bundle where Inertia looks for it', async () => {
    const client = await resolveConfig({}, 'build');
    const server = await resolveConfig({ build: { ssr: true } }, 'build');
    assert.equal(resolve(client.build.outDir), resolve('public/build'));
    assert.equal(resolve(server.build.outDir), resolve('bootstrap/ssr'));
});

test('the portal build requires the emitted file for the MapLibre worker entry', (t) => {
    const build = mkdtempSync(join(tmpdir(), 'ernie-map-worker-'));
    t.after(() => rmSync(build, { recursive: true, force: true }));
    const script = fileURLToPath(new URL('../../scripts/check-portal-map-worker.mjs', import.meta.url));
    const check = () => spawnSync(execPath, [script, build], { encoding: 'utf8' });
    const manifest = {
        other: { isWorkerEntry: true, src: 'other.js', file: 'other.js' },
        map: { src: 'node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs', file: 'map-hash.js' },
    };
    writeFileSync(join(build, 'other.js'), '');
    writeFileSync(join(build, 'manifest.json'), JSON.stringify(manifest));
    const wrongEntry = check();
    assert.notEqual(wrongEntry.status, 0);
    assert.match(wrongEntry.stderr, /no worker entry for .*maplibre-gl-worker/);

    manifest.map.isWorkerEntry = true;
    writeFileSync(join(build, 'manifest.json'), JSON.stringify(manifest));
    const missing = check();
    assert.notEqual(missing.status, 0);
    assert.match(missing.stderr, /missing from the build: map-hash\.js/);
    writeFileSync(join(build, 'map-hash.js'), 'self.onmessage = () => {};');
    assert.equal(check().status, 0);

    manifest.map.src = '../html/node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs';
    writeFileSync(join(build, 'manifest.json'), JSON.stringify(manifest));
    assert.equal(check().status, 0);
});
