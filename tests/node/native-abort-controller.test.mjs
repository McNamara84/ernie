import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const require = createRequire(import.meta.url);

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
