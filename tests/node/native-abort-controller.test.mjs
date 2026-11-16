import assert from 'node:assert/strict';
import { lstatSync, readFileSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);

test('Swagger Client resolves the workspace adapter without a dangling nested dependency', () => {
    const ponyfillPath = require.resolve('swagger-client/lib/helpers/abortcontroller-ponyfill.node.js');
    const swaggerRequire = createRequire(ponyfillPath);
    const swaggerRoot = resolve(dirname(ponyfillPath), '../..');
    const nestedDependency = resolve(swaggerRoot, 'node_modules/node-abort-controller');
    // Node can skip a broken nested link and fall back to the root package.
    // Check any installed nested entry before checking successful resolution.
    if (lstatSync(nestedDependency, { throwIfNoEntry: false })) {
        assert.doesNotThrow(() => realpathSync(nestedDependency));
    }
    const adapter = fileURLToPath(new URL('../../packages/native-abort-controller/index.cjs', import.meta.url));
    assert.equal(realpathSync(swaggerRequire.resolve('node-abort-controller')), realpathSync(adapter));
    const root = fileURLToPath(new URL('../../', import.meta.url));
    const lock = JSON.parse(readFileSync(new URL('../../package-lock.json', import.meta.url), 'utf8'));
    for (const [path, entry] of Object.entries(lock.packages)) {
        if (path.endsWith('/node-abort-controller') && entry.link) {
            assert.equal(realpathSync(resolve(root, entry.resolved)), realpathSync(dirname(adapter)), path);
        }
    }
});

test('Swagger Client uses the native cancellation APIs instead of the deprecated polyfill', () => {
    const ponyfill = require('swagger-client/lib/helpers/abortcontroller-ponyfill.node.js');
    assert.equal(ponyfill.AbortController, globalThis.AbortController);
    assert.equal(ponyfill.AbortSignal, globalThis.AbortSignal);
});

test('the compatibility exports abort native fetch with the original reason', async () => {
    const { AbortController, AbortSignal } = require('../../packages/native-abort-controller/index.cjs');
    const controller = new AbortController();
    const reason = new Error('cancelled by caller');
    let events = 0;
    controller.signal.addEventListener('abort', () => events++, { once: true });
    controller.abort(reason);
    controller.abort(new Error('second cancellation'));
    assert.equal(events, 1);
    assert.equal(controller.signal.reason, reason);
    assert.throws(
        () => controller.signal.throwIfAborted(),
        (error) => error === reason,
    );
    assert.equal(AbortSignal.any([controller.signal]).reason, reason);
    await assert.rejects(fetch('data:text/plain,test', { signal: controller.signal }), (error) => error === reason);
});
