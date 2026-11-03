import { spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { appendFileSync, closeSync, cpSync, existsSync, mkdirSync, mkdtempSync, openSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { extensionBuildFailure, hasTestFailure, includeSnapshotPath, jsonLdFailure, labels, platformFailures, resultOf, runtimePins, selectRelease, skipFollowing, stages, summaryOf } from './ci/php86-result.mjs';
import { composeArguments } from './docker-compose.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));
const output = resolve(process.env.ERNIE_PHP86_REPORT_DIR || join(root, '.tmp/php86-report'));
const reportPath = join(output, 'report.json');
const statePath = join(output, 'state.json');
mkdirSync(output, { recursive: true });
const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'));
const hash = (path) => createHash('sha256').update(readFileSync(path)).digest('hex');
let report;
let state;

class CheckFailure extends Error {
    constructor(message, status = 'unavailable') { super(message); this.status = status; }
}

function save() {
    report.result = resultOf(report);
    writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`);
    writeFileSync(join(output, 'summary.md'), summaryOf(report));
    if (state) writeFileSync(statePath, `${JSON.stringify(state, null, 2)}\n`);
}

function load() {
    report = readJson(reportPath);
    state = existsSync(statePath) ? readJson(statePath) : undefined;
}

function environment() {
    return { ...process.env, ...state.environment, ERNIE_PHP86_COMPOSE: join(root, 'docker-compose.php86.yml'),
        ERNIE_PHP86_ENV_FILE: join(state.workspace, '.env.docker'), COMPOSE_PROJECT_NAME: state.project,
        ERNIE_PEST_REPORT_DIR: join(output, 'backend'), ERNIE_MYSQL_REPORT_DIR: join(output, 'mysql') };
}

function command(executable, args, log, { timeout = 30 * 60_000, capture = false, cwd = state?.workspace || root } = {}) {
    const path = join(output, `${log}.log`);
    console.log(`[php86] ${log}`);
    const descriptor = openSync(path, 'w');
    let result;
    try {
        result = spawnSync(executable, args, { cwd, env: state?.environment ? environment() : process.env,
            timeout, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024,
            stdio: ['ignore', capture ? 'pipe' : descriptor, descriptor] });
        if (capture && result.stdout) appendFileSync(path, result.stdout);
    } finally { closeSync(descriptor); }
    if (result.error || result.signal || result.status !== 0) {
        const tail = readFileSync(path, 'utf8').slice(-6000);
        console.error(tail);
        const error = new CheckFailure(`${log} failed (${result.error?.code ?? result.signal ?? result.status}); see ${log}.log.`);
        error.log = path;
        error.stdout = result.stdout;
        error.exitCode = result.status;
        throw error;
    }
    return result.stdout?.trim();
}

function docker(args, log, options) {
    return command('docker', [...composeArguments(environment()), ...args], log, options);
}
function php(args, log, options) {
    return docker(['exec', '-T', 'app', 'php', '-d', 'memory_limit=2G', ...args], log, options);
}
function composer(args, log, options) { return php(['/usr/bin/composer', ...args], log, options); }

async function getJson(url) {
    const response = await fetch(url, { signal: AbortSignal.timeout(45_000) });
    if (!response.ok) throw new CheckFailure(`Release metadata request failed: HTTP ${response.status}.`);
    return response.json();
}

function assertSnapshot() {
    for (const file of ['composer.json', 'composer.lock']) {
        if (hash(join(state.workspace, file)) !== state.hashes[file]) {
            throw new CheckFailure(`${file} changed during the compatibility check.`);
        }
    }
}

async function setup() {
    const stable = await getJson('https://www.php.net/releases/index.php?json&version=8.6');
    const qa = stable.error === 'Unknown version' ? await getJson('https://www.php.net/pre-release-builds.php?format=json') : {};
    const release = selectRelease(stable, qa);
    const tag = `php:${release.version}-fpm-trixie`;
    const manifest = JSON.parse(command('docker', ['buildx', 'imagetools', 'inspect', tag, '--format', '{{json .Manifest}}'], 'image-manifest', { capture: true, timeout: 120_000 }));
    if (!/^sha256:[a-f0-9]{64}$/.test(manifest.digest ?? '')) throw new CheckFailure('Registry returned an invalid PHP image digest.');
    const pins = runtimePins(readFileSync(join(root, 'docker-compose.dev.yml'), 'utf8'));
    const project = `ernie-php86-${randomBytes(8).toString('hex')}`;
    const workspace = mkdtempSync(join(tmpdir(), 'ernie-php86-'));
    state = { project, workspace, hashes: Object.fromEntries(['composer.json', 'composer.lock'].map((file) => [file, hash(join(root, file))])),
        environment: { ERNIE_PHP86_WORKSPACE: workspace.replaceAll('\\', '/'), ERNIE_PHP86_IMAGE: `${tag}@${manifest.digest}`,
            ERNIE_PHP86_MYSQL_IMAGE: pins.mysql, ERNIE_PHP86_REDIS_IMAGE: pins.redis, ERNIE_PHP86_REFRESH: new Date().toISOString().slice(0, 10) } };
    writeFileSync(join(workspace, '.ernie-php86-workspace'), project);
    save();
    const listing = spawnSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
    if (listing.error || listing.status !== 0) throw new CheckFailure('Could not enumerate the source snapshot.');
    for (const relative of new Set(listing.stdout.split('\0').filter(Boolean))) {
        const source = join(root, relative);
        if (!includeSnapshotPath(relative) || !existsSync(source)) continue;
        const destination = resolve(workspace, relative);
        if (!destination.startsWith(workspace + sep)) throw new CheckFailure('Source snapshot path escaped its workspace.');
        mkdirSync(dirname(destination), { recursive: true });
        cpSync(source, destination);
    }
    for (const dir of ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/testing', 'storage/logs', 'bootstrap/cache', 'database']) mkdirSync(join(workspace, dir), { recursive: true });
    const settings = { APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: 'http://localhost', ASSET_URL: 'http://localhost', DB_CONNECTION: 'sqlite', DB_DATABASE: '/var/www/html/database/database.sqlite', CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array' };
    let dotenv = readFileSync(join(root, '.env.example'), 'utf8');
    for (const [key, value] of Object.entries(settings)) {
        const pattern = new RegExp(`^${key}=.*$`, 'm');
        dotenv = pattern.test(dotenv) ? dotenv.replace(pattern, `${key}=${value}`) : `${dotenv}\n${key}=${value}\n`;
    }
    writeFileSync(join(workspace, '.env'), dotenv);
    writeFileSync(join(workspace, '.env.docker'), 'COMPOSE_IGNORE_ORPHANS=true\n');
    writeFileSync(join(workspace, 'database/database.sqlite'), '');
    report.metadata = { ...report.metadata, php: release.version, channel: release.channel, image: state.environment.ERNIE_PHP86_IMAGE,
        mysqlImage: pins.mysql, lockSha256: state.hashes['composer.lock'] };
    save();
    try { docker(['build', '--pull', 'app'], 'environment-build'); }
    catch (error) {
        const failure = error.log && extensionBuildFailure(readFileSync(error.log, 'utf8'));
        if (failure) {
            error.status = 'incompatible';
            error.message = `Required extension ${failure.extension} does not compile against PHP ${release.version}: ${failure.diagnostic}`;
        }
        throw error;
    }
    docker(['--profile', 'test', 'up', '-d', '--wait', 'app', 'db', 'db-test', 'redis'], 'environment-start', { timeout: 180_000 });
    const runtime = JSON.parse(php(['-r', 'echo json_encode(["version"=>PHP_VERSION,"id"=>PHP_VERSION_ID,"memory"=>ini_get("memory_limit"),"extensions"=>get_loaded_extensions()]);'], 'environment-runtime', { capture: true }));
    if (runtime.id < 80600 || runtime.id >= 80700 || runtime.version !== release.version || runtime.memory !== '2G') {
        throw new CheckFailure('The running PHP version or memory limit differs from the selected target.');
    }
    const required = ['pdo_mysql', 'pdo_sqlite', 'mbstring', 'exif', 'pcntl', 'bcmath', 'gd', 'zip', 'sodium', 'xsl', 'intl', 'sockets', 'redis'];
    const missing = required.filter((extension) => !runtime.extensions.includes(extension));
    if (missing.length) throw new CheckFailure(`Required runtime extensions are missing: ${missing.join(', ')}.`, 'incompatible');
    report.metadata.extensions = runtime.extensions.sort().join(', ');
    report.metadata.composer = composer(['--version', '--no-ansi'], 'composer-version', { capture: true });
}

function platformCheck(args, log) {
    try { composer(['check-platform-reqs', ...args, '--format=json'], log, { capture: true }); }
    catch (error) {
        const failures = platformFailures(error.stdout);
        if (failures.length) {
            error.status = 'incompatible';
            error.message = failures.map((row) => `${row.name}: ${row.failed_requirement?.source ?? 'locked dependencies'} requires ${row.failed_requirement?.constraint ?? 'an available extension'}; actual ${row.version ?? 'missing'}.`).join(' ');
        }
        throw error;
    }
}

function install() {
    composer(['validate', '--no-check-publish'], 'composer-validate');
    platformCheck(['--lock'], 'composer-platform-lock');
    composer(['install', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], 'composer-install');
    platformCheck([], 'composer-platform-installed');
    docker(['exec', '-T', 'app', 'node', 'scripts/verify-php-dependencies.mjs'], 'composer-installed-lock');
    php(['artisan', 'key:generate', '--force'], 'laravel-bootstrap');
    php(['artisan', 'migrate:fresh', '--seed', '--force'], 'laravel-migrations');
}

function tests(stage, script) {
    try { command(process.execPath, [join(root, 'scripts', script)], stage); }
    catch (error) {
        const dir = join(output, stage);
        if (existsSync(dir) && readdirSync(dir).filter((file) => file.endsWith('.xml')).some((file) => hasTestFailure(readFileSync(join(dir, file), 'utf8')))) error.status = 'incompatible';
        throw error;
    }
}

const actions = {
    environment: setup,
    composer: install,
    backend() {
        tests('backend', 'run-pest.mjs');
        try { command(process.execPath, [join(root, 'scripts/run-jsonld.mjs')], 'backend-jsonld'); }
        catch (error) {
            const failure = error.log && jsonLdFailure(readFileSync(error.log, 'utf8'));
            if (failure !== undefined) {
                error.status = 'incompatible';
                error.message = `JSON-LD semantic validation failed: ${failure}`;
            }
            throw error;
        }
    },
    mysql() {
        report.metadata.mysql = docker(['exec', '-T', 'db-test', 'mysql', '-uroot', '-prootsecret', '--batch', '--skip-column-names', '-e', 'SELECT VERSION()'], 'mysql-version', { capture: true });
        tests('mysql', 'run-mysql-tests.mjs');
    },
    phpstan() {
        writeFileSync(join(state.workspace, 'phpstan.php86.neon'), 'includes:\n    - phpstan.neon\nparameters:\n    phpVersion: 80600\n');
        try { command(process.execPath, [join(root, 'scripts/run-phpstan.mjs'), '--configuration=phpstan.php86.neon', '--error-format=json', '--no-progress'], 'phpstan'); }
        catch (error) {
            const log = error.log && readFileSync(error.log, 'utf8');
            const start = log?.indexOf('{"totals"');
            if (start >= 0) {
                try {
                    const result = JSON.parse(log.slice(start, log.lastIndexOf('}') + 1));
                    if (result.totals.file_errors > 0 || result.totals.errors > 0) error.status = 'incompatible';
                } catch { /* A broken analyzer is not a completed analysis. */ }
            }
            throw error;
        }
    },
};

async function runStage(name) {
    if (!stages.includes(name)) throw new Error('Unknown PHP 8.6 stage.');
    if (name === 'environment') {
        if (existsSync(statePath)) { load(); cleanup(); }
        report = { schemaVersion: 1, metadata: { target: process.env.ERNIE_PHP86_TARGET || 'local worktree',
            commit: spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout?.trim() || 'unknown',
            prHead: process.env.ERNIE_PHP86_PR_HEAD || 'n/a', startedAt: new Date().toISOString() },
            stages: Object.fromEntries(stages.map((stage) => [stage, { status: 'pending' }])) };
        state = undefined;
        for (const dir of ['backend', 'mysql']) rmSync(join(output, dir), { recursive: true, force: true });
    } else {
        load();
        if (stages.slice(0, stages.indexOf(name)).some((stage) => report.stages[stage].status !== 'passed')) {
            throw new Error('A previous compatibility stage did not pass.');
        }
    }
    const started = Date.now();
    report.stages[name] = { status: 'running' };
    save();
    try {
        if (state) assertSnapshot();
        await actions[name]();
        assertSnapshot();
        report.stages[name] = { status: 'passed', seconds: Math.round((Date.now() - started) / 1000) };
    } catch (error) {
        report.stages[name] = { status: error.status ?? 'unavailable', reason: error.message, seconds: Math.round((Date.now() - started) / 1000) };
        skipFollowing(report, name);
        console.error(`[php86] ${labels[name]}: ${error.message}`);
    }
    save();
    return report.stages[name].status === 'passed';
}

function cleanup() {
    if (!state) return;
    const workspace = resolve(state.workspace);
    if (!/^ernie-php86-[a-f0-9]{16}$/.test(state.project) || !workspace.startsWith(resolve(tmpdir()) + sep)
        || !basename(workspace).startsWith('ernie-php86-')
        || readFileSync(join(workspace, '.ernie-php86-workspace'), 'utf8') !== state.project) {
        throw new Error('Refusing cleanup of an unrecognized compatibility workspace.');
    }
    docker(['--profile', 'test', 'down', '--volumes', '--remove-orphans'], 'cleanup', { timeout: 120_000 });
    rmSync(workspace, { recursive: true, force: true });
    rmSync(statePath, { force: true });
    state = undefined;
}

try {
    const [mode, stage] = process.argv.slice(2);
    if (mode === '--report') {
        try { load(); } catch { report = { schemaVersion: 1, stages: {}, metadata: { target: process.env.ERNIE_PHP86_TARGET || 'unknown' } }; }
        save();
        if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, summaryOf(report));
        if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `label=${report.result.label}\nstatus=${report.result.status}\n`);
        console.log(`PHP 8.6: ${report.result.label}`);
    } else if (mode === '--cleanup') {
        if (existsSync(statePath)) { load(); cleanup(); }
    } else if (mode === '--stage') {
        if (!await runStage(stage)) process.exitCode = resultOf(report).status === 'incompatible' ? 1 : 2;
    } else if (!mode) {
        try {
            for (const name of stages) if (!await runStage(name)) break;
            console.log(`PHP 8.6: ${resultOf(report).label}; ${join(output, 'summary.md')}`);
            process.exitCode = resultOf(report).status === 'compatible' ? 0 : resultOf(report).status === 'incompatible' ? 1 : 2;
        } finally { cleanup(); }
    } else throw new Error('Usage: npm run test:php:8.6 -- [--stage NAME | --report | --cleanup]');
} catch (error) { console.error(`[php86] ${error.message}`); process.exitCode = 2; }
