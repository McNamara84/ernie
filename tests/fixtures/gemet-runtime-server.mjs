import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import process from 'node:process';

const configurationPath = process.argv[2];
const groupPrefix = 'http://www.eionet.europa.eu/gemet/group/';
const entity = (uri) => ({ uri, preferredLabel: { string: 'Runtime fixture', language: 'en' } });
const started = performance.now();
const events = [];
const attempts = {};
let active = 0;
let maximumActive = 0;

const server = createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/metrics') {
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ events, attempts, active, maximumActive }));
        return;
    }

    const { mode, groups = 9 } = JSON.parse(readFileSync(configurationPath, 'utf8'));
    const uri = url.searchParams.get('concept_uri') ?? url.searchParams.get('thesaurus_uri');
    const attempt = (attempts[uri] = (attempts[uri] ?? 0) + 1);
    const member = url.pathname === '/gemet/getRelatedConcepts';
    const record = (type) => events.push({ type, uri, attempt, seconds: (performance.now() - started) / 1000 });
    record('start');
    active++;
    maximumActive = Math.max(maximumActive, active);
    let finished = false;
    let timer;
    const finish = (type) => {
        if (finished) return;
        finished = true;
        clearTimeout(timer);
        active--;
        record(type);
    };
    response.once('finish', () => finish('finish'));
    response.once('close', () => finish('disconnect'));

    let delay = member ? 50 : 0;
    if (member && mode === 'parallel') delay = [400, 300, 200, 50][(Number(uri.split('/').at(-1)) - 96) % 4];
    if (member && mode === 'timeout' && uri === `${groupPrefix}96` && attempt === 1) delay = 31000;
    if (member && mode === 'deadline') delay = 24000;

    timer = setTimeout(() => {
        if (member && mode === 'retry-after' && uri === `${groupPrefix}96` && attempt === 1) {
            response.writeHead(429, { 'Content-Type': 'application/json', 'Retry-After': '1' });
            response.end('{}');
            return;
        }
        let entities;
        if (!member) {
            entities = uri.includes('/supergroup/')
                ? [entity('http://www.eionet.europa.eu/gemet/supergroup/1')]
                : Array.from({ length: groups }, (_, index) => entity(`${groupPrefix}${96 + index}`));
        } else {
            // Dropped or duplicated pool results must change the total count.
            entities = Array.from({ length: Number(uri.split('/').at(-1)) - 95 }, (_, index) =>
                entity(`http://www.eionet.europa.eu/gemet/concept/${index}`),
            );
        }
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify(entities));
    }, delay);
});

server.listen(0, '127.0.0.1', () => {
    process.stdout.write(`http://127.0.0.1:${server.address().port}\n`);
});

process.once('SIGTERM', () => {
    server.closeAllConnections();
    server.close(() => process.exit(0));
});
