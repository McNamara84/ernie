import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import process from 'node:process';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const require = createRequire(import.meta.url);
const axiosRequire = createRequire(require.resolve('axios'));
const resolverRequire = createRequire(require.resolve('@hookform/resolvers/joi'));
const apidomRequire = createRequire(require.resolve('@swagger-api/apidom-reference'));

test('redirects strip custom credentials supplied in an array from another realm', async (t) => {
    const redirected = createServer((request, response) => {
        response.end(JSON.stringify({
            credential: request.headers['x-private-token'] ?? null,
            ordinary: request.headers['x-public-value'],
        }));
    });
    let targetPort;
    let originalCredential;
    const origin = createServer((request, response) => {
        originalCredential = request.headers['x-private-token'];
        response.writeHead(302, { location: `http://127.0.0.1:${targetPort}/metadata` });
        response.end();
    });
    t.after(() => Promise.all([origin, redirected].map((server) => new Promise((resolve) => {
        server.closeAllConnections();
        server.close(resolve);
    }))));
    await Promise.all([origin, redirected].map((server) => new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    })));
    targetPort = redirected.address().port;
    const { http } = axiosRequire('follow-redirects');
    const result = await new Promise((resolve, reject) => {
        const request = http.get({
            hostname: '127.0.0.1', port: origin.address().port, path: '/',
            headers: { 'X-Private-Token': 'synthetic-credential', 'X-Public-Value': 'visible' },
            sensitiveHeaders: runInNewContext("['X-Private-Token']"),
        }, (response) => {
            let body = '';
            response.setEncoding('utf8');
            response.on('data', (chunk) => { body += chunk; });
            response.once('end', () => resolve(JSON.parse(body)));
            response.once('error', reject);
        });
        request.once('error', reject);
        request.setTimeout(5000, () => request.destroy(new Error('Local redirect test timed out')));
    });
    assert.equal(originalCredential, 'synthetic-credential');
    assert.deepEqual(result, { credential: null, ordinary: 'visible' });
});

test('Joi validates multiple addresses and rejects long whitespace without blocking', () => {
    const joiPath = resolverRequire.resolve('joi');
    const Joi = resolverRequire('joi');
    const schema = Joi.string().email({ multiple: true });
    assert.equal(schema.validate('curator@example.com, reviewer@example.org').error, undefined);
    assert.equal(schema.validate('invalid, reviewer@example.org').error.details[0].type, 'string.email');

    // Bound the vulnerable operation in a separate process so a regression
    // cannot stall the whole tooling suite (GHSA-4528-x5fx-h6hg).
    const output = execFileSync(process.execPath, ['--eval', `
        const Joi = require(process.argv[1]);
        const result = Joi.string().email({ multiple: true }).validate(' '.repeat(200_000));
        console.log(result.error.details[0].type);
    `, joiPath], { encoding: 'utf8', timeout: 5000 });
    assert.equal(output.trim(), 'string.email');
});

test('Swagger glob preprocessing bounds expanded patterns before matching', () => {
    const output = execFileSync(process.execPath, ['--eval', `
        const { Minimatch } = require(process.argv[1]);
        const pattern = Array(22).fill('**/../catalog/item').join('/');
        const compiled = new Minimatch(pattern, { optimizationLevel: 2, braceExpandMax: 40 });
        console.log(compiled.set.length);
    `, apidomRequire.resolve('minimatch')], { encoding: 'utf8', timeout: 5000 });
    assert.ok(Number(output.trim()) > 0);
    assert.ok(Number(output.trim()) <= 40);
});

test('Swagger file resolver preserves its allowlist with the patched glob dependency', async () => {
    const { default: FileResolver } = await import('@swagger-api/apidom-reference/resolve/resolvers/file');
    const resolver = new FileResolver({ fileAllowList: ['*.json', /^\/schemas\/.*\.yaml$/u] });
    assert.equal(resolver.canRead({ uri: '/schemas/model.json' }), true);
    assert.equal(resolver.canRead({ uri: '/schemas/model.yaml' }), true);
    assert.equal(resolver.canRead({ uri: '/schemas/private.pem' }), false);
    assert.equal(resolver.canRead({ uri: 'https://example.org/model.json' }), false);
});
