import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { test } from 'node:test';

import { waitForPlaywrightBackend } from '../../scripts/wait-for-playwright-backend.mjs';

test('public login readiness waits through a gateway transition using real HTTP', async () => {
    let calls = 0;
    const server = createServer((request, response) => {
        assert.equal(request.url, '/login');
        response.writeHead(++calls === 1 ? 502 : 200, { 'Content-Type': 'text/html; charset=UTF-8' });
        response.end('<html></html>');
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    try {
        await waitForPlaywrightBackend(`http://127.0.0.1:${server.address().port}`, { intervalMs: 1 });
        assert.equal(calls, 2);
    } finally {
        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
    }
});

test('readiness has a bounded deadline for unavailable routing and connection resets', async () => {
    for (const request of [
        async () => ({ status: 503, contentType: 'text/html' }),
        async () => { throw Object.assign(new Error('connection reset'), { code: 'ECONNRESET' }); },
    ]) {
        let elapsed = 0;
        await assert.rejects(waitForPlaywrightBackend('https://ernie.localhost:3333', {
            request, now: () => elapsed, wait: async (duration) => { elapsed += duration; }, timeoutMs: 10, intervalMs: 3,
        }), /within 10 ms/u);
        assert.equal(elapsed, 10);
    }
});

test('readiness fails immediately for application errors and unexpected successful content', async () => {
    for (const response of [{ status: 500, contentType: 'text/html' }, { status: 200, contentType: 'application/json' }]) {
        await assert.rejects(waitForPlaywrightBackend('https://ernie.localhost:3333', {
            request: async () => response,
            wait: async () => assert.fail('Application errors must not be retried.'),
        }), /expected HTTP 200 HTML/u);
    }
});

test('readiness rejects credentials and non-HTTP origins before making requests', async () => {
    for (const origin of ['https://username:password@example.test', 'file:///tmp/server']) {
        await assert.rejects(waitForPlaywrightBackend(origin, {
            request: async () => assert.fail('Invalid origins must not be requested.'),
        }), /HTTP\(S\) origin without credentials/u);
    }
});
