import { randomBytes } from 'node:crypto';

const testIpPattern = /^2001:db8:ee00:(?:[0-9a-f]{4}:){4}[0-9a-f]{4}$/u;

export function createPlaywrightTestIp(bytes = randomBytes(10)) {
    if (!Buffer.isBuffer(bytes) || bytes.length !== 10) {
        throw new Error('A browser client identity requires exactly ten random bytes.');
    }
    return `2001:db8:ee00:${bytes.toString('hex').match(/.{4}/gu).join(':')}`;
}

export function playwrightClientHeaders(headers, ip) {
    if (!testIpPattern.test(ip)) throw new Error('Browser client IP must use the reserved test range.');
    return {
        ...headers,
        'X-ERNIE-Playwright-Test': '1',
        'X-ERNIE-Playwright-Client-IP': ip,
        // CI's direct Laravel server receives this header. Local Traefik strips
        // it, so the temporary test Nginx config forwards the marked identity.
        'X-Forwarded-For': ip,
    };
}

export function createPlaywrightNginxConfig(source) {
    const anchor = '        include fastcgi_params;';
    if (source.split(anchor).length !== 2 || source.includes('ernie_playwright_forwarded_for')) {
        throw new Error('Unexpected development Nginx configuration; refusing test routing changes.');
    }
    const map = [
        '# Only the temporary browser backend accepts reserved per-test identities.',
        'map "$http_x_ernie_playwright_test:$http_x_ernie_playwright_client_ip" $ernie_playwright_forwarded_for {',
        '    default $http_x_forwarded_for;',
        '    "~^1:(?<ernie_playwright_ip>2001:db8:ee00:(?:[0-9a-f]{4}:){4}[0-9a-f]{4})$" $ernie_playwright_ip;',
        '}',
        '',
    ].join('\n');
    return map + source.replace(anchor, `${anchor}\n        fastcgi_param HTTP_X_FORWARDED_FOR $ernie_playwright_forwarded_for;`);
}
