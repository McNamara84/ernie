import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { setTimeout } from 'node:timers/promises';
import { pathToFileURL } from 'node:url';

function requestStatus(url, timeoutMs) {
    return new Promise((resolve, reject) => {
        const request = url.protocol === 'https:' ? httpsRequest : httpRequest;
        const outgoing = request(url, {
            agent: false,
            // Match the dev-stack browser's self-signed certificate policy.
            rejectUnauthorized: false,
            signal: AbortSignal.timeout(timeoutMs),
        }, (response) => {
            response.resume();
            response.once('error', reject);
            response.once('end', () => resolve({ status: response.statusCode, contentType: response.headers['content-type'] ?? '' }));
        });
        outgoing.once('error', reject);
        outgoing.end();
    });
}

export async function waitForPlaywrightBackend(baseUrl, {
    request = requestStatus, wait = setTimeout, now = () => performance.now(), timeoutMs = 60_000, intervalMs = 250,
} = {}) {
    const url = new URL('/login', baseUrl);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
        throw new Error('Browser readiness requires an HTTP(S) origin without credentials.');
    }
    const deadline = now() + timeoutMs;
    let lastIssue = 'no response';
    while (now() < deadline) {
        try {
            const response = await request(url, Math.max(1, Math.ceil(Math.min(5_000, deadline - now()))));
            lastIssue = `HTTP ${response.status}`;
            if (response.status === 200 && /^text\/html(?:;|$)/iu.test(response.contentType)) return;
            if (![502, 503, 504].includes(response.status)) {
                throw new Error(`Browser login readiness failed: ${lastIssue}; expected HTTP 200 HTML.`);
            }
        } catch (error) {
            if (!['ECONNREFUSED', 'ECONNRESET', 'EPIPE', 'ETIMEDOUT', 'ABORT_ERR'].includes(error.code)) throw error;
            lastIssue = error.code;
        }
        const remaining = deadline - now();
        if (remaining > 0) await wait(Math.min(intervalMs, remaining));
    }
    throw new Error(`Browser login did not become ready within ${timeoutMs} ms (${lastIssue}).`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    await waitForPlaywrightBackend(process.argv[2]);
}
