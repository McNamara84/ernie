import assert from 'node:assert/strict';
import test from 'node:test';

import { candidateDirectory, inspectCandidateContainers, releaseCompose } from '../../scripts/prepare-release-candidate.mjs';

const artifact = { schema: 1, repository: 'Example/ernie', branch: 'main', sha: 'a'.repeat(40), version: '1.1.1', images: {
    app: `ghcr.io/example/ernie-app@sha256:${'1'.repeat(64)}`,
    nginx: `ghcr.io/example/ernie-nginx@sha256:${'2'.repeat(64)}`,
    fuji: `ghcr.io/example/ernie-fuji@sha256:${'3'.repeat(64)}`,
} };
function template() {
    return {
        name: 'production', networks: { traefik: { external: true } }, volumes: { 'storage-data': { name: 'live-storage' } },
        services: Object.fromEntries(['app', 'queue', 'assistance-queue', 'assessment-queue', 'elmo-cache-refresh', 'scheduler', 'webserver', 'db', 'redis', 'fuji'].map((name) => [name, {
            container_name: `production-${name}`, image: name === 'db' ? 'mysql:9.7.2@sha256:abc' : 'unused',
            networks: { traefik: {} }, ports: [{ published: '80' }], labels: { 'traefik.enable': 'true' },
            environment: { APP_URL: 'https://production.example', DATACITE_ENDPOINT: 'https://api.datacite.org', FUJI_PASSWORD: '${FUJI_PASSWORD}', KEEP_DEFAULT: '${SOME_SETTING:-default}', },
            volumes: [{ type: 'volume', source: 'storage-data', target: '/var/www/html/storage' }, { type: 'bind', source: '/proc/stat', target: '/host/proc/stat' }],
        }])),
    };
}

test('candidate uses original images with independent containers, networks, volumes and credentials', () => {
    const source = template();
    const compose = releaseCompose(source, artifact);
    assert.equal(source.name, 'production');
    assert.equal(compose.name, `ernie-release-${artifact.sha.slice(0, 12)}`);
    assert.deepEqual(compose.networks, { release: { driver: 'bridge' } });
    assert.deepEqual(compose.volumes, { 'storage-data': {}, 'dockerized-laravel-data': {} });
    for (const service of Object.values(compose.services)) {
        assert.equal(service.container_name, undefined);
        assert.equal(service.labels, undefined);
        assert.ok(service.volumes.every((volume) => volume.type === 'volume'));
        assert.deepEqual(service.networks, { release: null });
    }
    for (const name of ['app', 'queue', 'assistance-queue', 'assessment-queue', 'elmo-cache-refresh', 'scheduler']) {
        const service = compose.services[name];
        assert.equal(service.image, artifact.images.app);
        assert.equal(service.environment.DATACITE_ENDPOINT, 'https://api.test.datacite.org');
        assert.equal(service.environment.DATACITE_TEST_MODE, 'true');
        assert.equal(service.environment.MAIL_MAILER, 'log');
        assert.equal(service.environment.ELMO_CACHE_REFRESH_ENABLED, 'false');
        assert.equal(service.environment.KEEP_DEFAULT, '${RELEASE_SOME_SETTING:-default}');
        assert.equal(service.ports, undefined);
    }
    assert.equal(compose.services.webserver.ports[0].host_ip, '127.0.0.1');
    assert.equal(compose.services.webserver.image, artifact.images.nginx);
    assert.equal(compose.services.fuji.image, artifact.images.fuji);
    assert.equal(compose.services.db.environment.MYSQL_PASSWORD, compose.services.app.environment.DB_PASSWORD);
});

test('candidate preparation rejects unknown services, old MySQL and unsafe output paths', () => {
    const unknown = template(); unknown.services.unreviewed = {};
    assert.throws(() => releaseCompose(unknown, artifact), /services changed/u);
    const old = template(); old.services.db.image = 'mysql:8.4';
    assert.throws(() => releaseCompose(old, artifact), /MySQL 9.7/u);
    for (const sha of ['../main', 'a'.repeat(39), 'A'.repeat(40), undefined]) assert.throws(() => candidateDirectory(sha), /40-character/u);
    assert.throws(() => releaseCompose(template(), { ...artifact, branch: 'dev' }), /branch/u);
});

test('all candidate app services receive the test credentials and repository ID used by Laravel', () => {
    const source = template();
    // The current Production template omits test settings. Also override any
    // future template defaults so the candidate stays on the test API.
    source.services.app.environment.DATACITE_TEST_ENDPOINT = '${DATACITE_TEST_ENDPOINT}';
    source.services.app.environment.DATACITE_USERNAME = '${DATACITE_USERNAME}';
    source.services.app.environment.DATACITE_PASSWORD = '${DATACITE_PASSWORD}';
    source.services.app.environment.DATACITE_CLIENT_ID = '${DATACITE_CLIENT_ID}';
    const compose = releaseCompose(source, artifact);
    for (const name of ['app', 'queue', 'assistance-queue', 'assessment-queue', 'elmo-cache-refresh', 'scheduler']) {
        const env = compose.services[name].environment;
        assert.equal(env.DATACITE_TEST_MODE, 'true');
        assert.equal(env.DATACITE_TEST_ENDPOINT, 'https://api.test.datacite.org');
        for (const setting of ['USERNAME', 'PASSWORD', 'CLIENT_ID']) {
            assert.equal(env[`DATACITE_TEST_${setting}`], '${RELEASE_DATACITE_TEST_' + setting + ':-}');
            assert.equal(env[`DATACITE_${setting}`], '');
        }
        assert.equal(env.DATACITE_IGSN_USERNAME, '');
        assert.equal(env.DATACITE_IGSN_PASSWORD, '');
    }
});

test('verification rejects another project, missing or unhealthy services, and substituted images', () => {
    const compose = releaseCompose(template(), artifact);
    const containers = Object.entries(compose.services).map(([name, service]) => ({
        Config: { Image: service.image, Env: ['APP_URL=http://localhost:8181'],
            Labels: { 'com.docker.compose.service': name, 'com.docker.compose.project': compose.name } },
        State: { Running: true, Health: { Status: 'healthy' } },
    }));
    const result = inspectCandidateContainers(containers, artifact);
    assert.equal(result.appUrl, 'http://localhost:8181');
    assert.equal(result.services.length, 10);
    assert.throws(() => inspectCandidateContainers(containers.slice(1), artifact), /required release service/u);
    const change = (modify) => { const copy = structuredClone(containers); modify(copy[0]); return copy; };
    assert.throws(() => inspectCandidateContainers(change((c) => { c.Config.Image = 'ghcr.io/example/ernie-app:latest'; }), artifact), /different image/u);
    assert.throws(() => inspectCandidateContainers(change((c) => { c.Config.Labels['com.docker.compose.project'] = 'production'; }), artifact), /Unexpected/u);
    assert.throws(() => inspectCandidateContainers(change((c) => { c.State.Health.Status = 'unhealthy'; }), artifact), /not healthy/u);
    assert.throws(() => inspectCandidateContainers(change((c) => { c.State.Running = false; }), artifact), /not healthy/u);
});
