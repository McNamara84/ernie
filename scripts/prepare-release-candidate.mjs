import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

import { validateArtifact } from './ci/deployment-artifacts.mjs';

const appServices = ['app', 'queue', 'curation-queue', 'assistance-queue', 'assessment-queue', 'elmo-cache-refresh', 'scheduler'];
const services = [...appServices, 'webserver', 'db', 'redis', 'fuji'];

function namespaceVariables(value) {
    if (typeof value === 'string') return value.replace(/(?<!\$)\$\{([A-Z][A-Z0-9_]*)/gu, '${RELEASE_$1');
    if (Array.isArray(value)) return value.map(namespaceVariables);
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, namespaceVariables(item)]));
    return value;
}

export function releaseCompose(template, artifact) {
    validateArtifact(artifact, { branch: 'main' });
    const compose = namespaceVariables(structuredClone(template));
    if (Object.keys(compose.services).sort().join() !== [...services].sort().join()) {
        throw new Error('Production services changed; review release stack isolation before proceeding.');
    }
    if (!/^mysql:9\.7\./u.test(compose.services.db.image)) throw new Error('Release tests require the pinned MySQL 9.7 server.');
    compose.name = `ernie-release-${artifact.sha.slice(0, 12)}`;
    compose.networks = { release: { driver: 'bridge' } };
    compose.volumes = { 'storage-data': {}, 'dockerized-laravel-data': {} };
    delete compose.configs;
    delete compose.secrets;
    for (const [name, service] of Object.entries(compose.services)) {
        for (const key of ['container_name', 'hostname', 'labels', 'ports', 'env_file', 'secrets', 'configs', 'build', 'network_mode', 'extra_hosts']) delete service[key];
        service.networks = { release: null };
        service.volumes = (service.volumes ?? []).filter((volume) => volume.type === 'volume');
        if (service.volumes.some((volume) => !Object.hasOwn(compose.volumes, volume.source))) throw new Error('Unexpected release volume.');
        service.pull_policy = 'always';
        if (appServices.includes(name)) {
            service.image = artifact.images.app;
            service.environment = {
                ...service.environment,
                APP_KEY: '${RELEASE_APP_KEY:?Set RELEASE_APP_KEY}',
                APP_URL: '${RELEASE_APP_URL:-http://localhost:8181}',
                SESSION_DOMAIN: '', SESSION_COOKIE: compose.name.replaceAll('-', '_'),
                SESSION_SECURE_COOKIE: '${RELEASE_SESSION_SECURE_COOKIE:-false}',
                SANCTUM_STATEFUL_DOMAINS: '${RELEASE_SANCTUM_STATEFUL_DOMAINS:-localhost:8181}',
                DB_HOST: 'db', DB_DATABASE: 'ernie_release', DB_USERNAME: 'ernie',
                DB_PASSWORD: '${RELEASE_DB_PASSWORD:?Set RELEASE_DB_PASSWORD}', // ggignore: Compose variable reference; the password is generated in the ignored candidate .env.
                REDIS_HOST: 'redis', REDIS_PASSWORD: 'null',
                DATACITE_ENDPOINT: 'https://api.test.datacite.org', DATACITE_TEST_MODE: 'true',
                DATACITE_TEST_ENDPOINT: 'https://api.test.datacite.org',
                DATACITE_TEST_USERNAME: '${RELEASE_DATACITE_TEST_USERNAME:-}',
                DATACITE_TEST_PASSWORD: '${RELEASE_DATACITE_TEST_PASSWORD:-}', // ggignore: Compose variable reference for a dedicated test account.
                DATACITE_TEST_CLIENT_ID: '${RELEASE_DATACITE_TEST_CLIENT_ID:-}',
                DATACITE_USERNAME: '', DATACITE_PASSWORD: '', DATACITE_CLIENT_ID: '',
                DATACITE_IGSN_USERNAME: '', DATACITE_IGSN_PASSWORD: '', DATACITE_IGSN_CLIENT_ID: '',
                ELMO_CACHE_REFRESH_ENABLED: 'false', ELMO_MSL_CACHE_REFRESH_READY: 'false',
                MAIL_MAILER: 'log', SYSTEM_METRICS_ENABLED: 'false', PUBLIC_TRAFFIC_ENABLED: 'false',
                FUJI_BASE_URL: 'http://fuji:1071', FUJI_USERNAME: 'ernie',
                FUJI_PASSWORD: '${RELEASE_FUJI_PASSWORD:?Set RELEASE_FUJI_PASSWORD}',
            };
        }
    }
    compose.services.db.environment = {
        MYSQL_DATABASE: 'ernie_release', MYSQL_USER: 'ernie',
        MYSQL_PASSWORD: '${RELEASE_DB_PASSWORD:?Set RELEASE_DB_PASSWORD}', // ggignore: Same Compose variable reference as the app; no embedded password.
        MYSQL_ROOT_PASSWORD: '${RELEASE_DB_ROOT_PASSWORD:?Set RELEASE_DB_ROOT_PASSWORD}',
    };
    compose.services.webserver.image = artifact.images.nginx;
    compose.services.webserver.ports = [{ target: 80, published: '${RELEASE_PORT:-8181}', host_ip: '127.0.0.1', protocol: 'tcp' }];
    compose.services.fuji.image = artifact.images.fuji;
    compose.services.fuji.environment.FUJI_USERNAME = 'ernie';
    compose.services.fuji.environment.FUJI_PASSWORD = '${RELEASE_FUJI_PASSWORD:?Set RELEASE_FUJI_PASSWORD}';
    return compose;
}

export function candidateDirectory(sha) {
    if (!/^[a-f0-9]{40}$/u.test(sha ?? '')) throw new Error('Supply the full 40-character candidate commit SHA.');
    return resolve('.release-candidate', sha);
}

const run = (program, args, options = {}) => execFileSync(program, args, { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], ...options }).trim();

export function prepareCandidate(sha) {
    const output = candidateDirectory(sha);
    const artifactCommit = run('git', ['rev-parse', 'refs/remotes/origin/artifacts/main']);
    const record = validateArtifact(JSON.parse(run('git', ['show', `${artifactCommit}:${sha}.json`])), { branch: 'main', sha });
    const remote = run('git', ['remote', 'get-url', 'origin']);
    const repository = remote.match(/github\.com[/:]([^/]+\/[^/]+?)(?:\.git)?$/iu)?.[1];
    if (!repository || record.repository.toLowerCase() !== repository.toLowerCase()) throw new Error('Artifact repository does not match origin.');
    run('git', ['merge-base', '--is-ancestor', sha, 'refs/remotes/origin/main']);
    const scratch = mkdtempSync(join(tmpdir(), 'ernie-release-compose-'));
    try {
        const templatePath = join(scratch, 'compose.yml');
        const emptyEnv = join(scratch, '.env');
        writeFileSync(emptyEnv, '');
        writeFileSync(templatePath, run('git', ['show', `${sha}:docker-compose.prod.yml`]));
        const template = JSON.parse(run('docker', ['compose', '--env-file', emptyEnv, '-f', templatePath, 'config', '--no-interpolate', '--format', 'json']));
        const compose = releaseCompose(template, record);
        mkdirSync(output, { recursive: true });
        writeFileSync(join(output, 'compose.json'), `${JSON.stringify(compose, null, 2)}\n`);
        writeFileSync(join(output, 'artifact.json'), `${JSON.stringify({ ...record, artifactCommit }, null, 2)}\n`);
        const envPath = join(output, '.env');
        if (!existsSync(envPath)) {
            writeFileSync(envPath, [
                `RELEASE_APP_KEY=base64:${randomBytes(32).toString('base64')}`,
                `RELEASE_DB_PASSWORD=${randomBytes(24).toString('hex')}`,
                `RELEASE_DB_ROOT_PASSWORD=${randomBytes(24).toString('hex')}`,
                `RELEASE_FUJI_PASSWORD=${randomBytes(24).toString('hex')}`,
                'RELEASE_PORT=8181', 'RELEASE_APP_URL=http://localhost:8181', '',
            ].join('\n'), { mode: 0o600 });
        }
        run('docker', ['compose', '--env-file', envPath, '-f', join(output, 'compose.json'), 'config', '--quiet']);
        return output;
    } finally {
        rmSync(scratch, { recursive: true, force: true });
    }
}

export function inspectCandidateContainers(containers, record) {
    validateArtifact(record, { branch: 'main' });
    const project = `ernie-release-${record.sha.slice(0, 12)}`;
    const found = new Set();
    for (const container of containers) {
        const name = container.Config.Labels['com.docker.compose.service'];
        if (!services.includes(name) || container.Config.Labels['com.docker.compose.project'] !== project) throw new Error('Unexpected service or project in release stack.');
        found.add(name);
        if (!container.State.Running || (container.State.Health && container.State.Health.Status !== 'healthy')) throw new Error(`Release service ${name} is not healthy.`);
        const kind = appServices.includes(name) ? 'app' : ({ webserver: 'nginx', fuji: 'fuji' })[name];
        if (kind && container.Config.Image !== record.images[kind]) throw new Error(`Release service ${name} is running a different image.`);
    }
    if (services.some((name) => !found.has(name))) throw new Error('A required release service is missing.');
    const app = containers.find((container) => container.Config.Labels['com.docker.compose.service'] === 'app');
    const appUrl = app.Config.Env.find((entry) => entry.startsWith('APP_URL='))?.slice('APP_URL='.length);
    if (!appUrl) throw new Error('Candidate app has no APP_URL.');
    return { appUrl, services: [...found].sort() };
}

export async function verifyCandidate(sha) {
    const output = candidateDirectory(sha);
    const record = validateArtifact(JSON.parse(readFileSync(join(output, 'artifact.json'), 'utf8')), { branch: 'main', sha });
    const args = ['compose', '-p', `ernie-release-${sha.slice(0, 12)}`, '--env-file', join(output, '.env'), '-f', join(output, 'compose.json')];
    const ids = run('docker', [...args, 'ps', '--all', '--quiet']).split(/\r?\n/u).filter(Boolean);
    if (!ids.length) throw new Error('The release stack has no containers.');
    const containers = JSON.parse(run('docker', ['inspect', ...ids]));
    const { appUrl, services: runningServices } = inspectCandidateContainers(containers, record);
    const health = new URL('/health', appUrl);
    const response = await fetch(health, { signal: AbortSignal.timeout(15_000), redirect: 'error' });
    if (!response.ok) throw new Error(`Candidate health endpoint returned ${response.status}.`);
    const report = { sha, images: record.images, verifiedAt: new Date().toISOString(), health: health.toString(), services: runningServices };
    writeFileSync(join(output, 'verification.json'), `${JSON.stringify(report, null, 2)}\n`);
    return report;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    try {
        const verify = process.argv[2] === '--verify';
        const sha = process.argv[verify ? 3 : 2];
        if (verify) {
            console.log(JSON.stringify(await verifyCandidate(sha), null, 2));
        } else {
            const output = prepareCandidate(sha);
            console.log(`Prepared candidate ${sha}. Credentials stay in ${join(output, '.env')}.`);
            console.log(`docker compose -p "ernie-release-${sha.slice(0, 12)}" --env-file "${join(output, '.env')}" -f "${join(output, 'compose.json')}" up -d --wait`);
        }
    } catch (error) {
        // Child-process stderr may contain rendered environment values. Report
        // the operation, never include command output or configuration secrets.
        console.error(error.status !== undefined ? 'Candidate Docker/Git operation failed. Check access and the documented prerequisites.' : error.message);
        process.exitCode = 1;
    }
}
