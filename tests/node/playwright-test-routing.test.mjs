import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { createPlaywrightNginxConfig, createPlaywrightTestIp, playwrightClientHeaders } from '../../scripts/playwright-test-routing.mjs';

test('browser clients use independent valid addresses in the reserved documentation range', () => {
    assert.equal(createPlaywrightTestIp(Buffer.from('00010002000300040005', 'hex')), '2001:db8:ee00:0001:0002:0003:0004:0005');
    const generated = new Set(Array.from({ length: 100 }, () => createPlaywrightTestIp()));
    assert.equal(generated.size, 100);
    assert.ok([...generated].every((ip) => /^2001:db8:ee00:(?:[0-9a-f]{4}:){4}[0-9a-f]{4}$/u.test(ip)));
});

test('invalid browser identity material fails before creating headers', () => {
    for (const value of [Buffer.alloc(9), Buffer.alloc(11), '00010002000300040005']) {
        assert.throws(() => createPlaywrightTestIp(value), /ten random bytes/u);
    }
    for (const ip of ['127.0.0.1', '2001:db8:ee00:0001:0002:0003:0004:0005,127.0.0.1', '2001:db8:ee00:0001:0002:0003:0004:0005\r\nX-Other: 1']) {
        assert.throws(() => playwrightClientHeaders({}, ip), /reserved test range/u);
    }
});

test('client identity preserves configured headers and forwards the same identity locally and in CI', () => {
    const original = { Accept: 'application/json', 'X-Configured-Test-Header': 'retained' };
    const ip = createPlaywrightTestIp(Buffer.alloc(10, 1));
    const headers = playwrightClientHeaders(original, ip);
    assert.equal(headers.Accept, original.Accept);
    assert.equal(headers['X-Configured-Test-Header'], original['X-Configured-Test-Header']);
    assert.equal(headers['X-ERNIE-Playwright-Test'], '1');
    assert.equal(headers['X-ERNIE-Playwright-Client-IP'], ip);
    assert.equal(headers['X-Forwarded-For'], ip);
    assert.equal(Object.keys(original).length, 2);
});

test('temporary Nginx routing preserves every development directive and ordinary forwarded addresses', () => {
    const original = readFileSync(new URL('../../docker/nginx/conf.d/dev.conf', import.meta.url), 'utf8');
    const configuration = createPlaywrightNginxConfig(original);
    const mapEnd = configuration.indexOf('}\n');
    assert.ok(mapEnd > 0);
    assert.ok(configuration.slice(0, mapEnd).includes('default $http_x_forwarded_for;'));
    assert.ok(configuration.slice(0, mapEnd).includes('~^1:(?<ernie_playwright_ip>2001:db8:ee00:'));
    assert.equal(
        configuration.slice(mapEnd + 2).replace('\n        fastcgi_param HTTP_X_FORWARDED_FOR $ernie_playwright_forwarded_for;', ''),
        original,
    );
    assert.equal(configuration.match(/fastcgi_param HTTP_X_FORWARDED_FOR/gu).length, 1);
});

test('unexpected or already modified Nginx inputs fail before the test configuration is written', () => {
    for (const source of [
        '',
        'include fastcgi_params;',
        '        include fastcgi_params;\n        include fastcgi_params;',
        'ernie_playwright_forwarded_for\n        include fastcgi_params;',
    ]) {
        assert.throws(() => createPlaywrightNginxConfig(source), /Unexpected development Nginx/u);
    }
});
