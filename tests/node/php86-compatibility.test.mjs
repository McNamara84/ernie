import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import process from 'node:process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { extensionBuildFailure, hasPhpstanFailure, hasTestFailure, includeSnapshotPath, jsonLdFailure, platformFailures, resultOf, runtimePins, selectRelease, skipFollowing, stages, summaryOf } from '../../scripts/ci/php86-result.mjs';
import { composeArguments } from '../../scripts/docker-compose.mjs';

const digest = 'a'.repeat(64);
const stable = { version: '8.6.1', date: '2026-12-01', source: [{ filename: 'php-8.6.1.tar.xz' }] };
const qa = { '8.6.0': { active: true, release: { type: 'RC', number: 4, sha256_xz: digest } } };

test('the source snapshot retains committed environment templates and vocabulary fixtures while excluding private runtime data', () => {
    for (const file of ['.env.example', '.env.docker.example', '.env.production', '.env.testing', 'storage/app/testing/gcmd-instruments.json', 'storage/app/private/.gitignore', '.github/workflows/tests.yml']) {
        assert.equal(includeSnapshotPath(file), true, file);
    }
    for (const file of ['.env', '.env.docker', '.env.local', '.env-key.secret', '.env.production.local', 'storage/app/private/export.sql', 'storage/logs/laravel.log', 'storage/inertia-devtools/history.json', 'public/build/manifest.json', '.trivy-cache/db/trivy.db', 'vendor/autoload.php', 'node_modules/package.json', 'docker/traefik/certs/private.key']) {
        assert.equal(includeSnapshotPath(file), false, file);
    }
});
function complete() {
    return { schemaVersion: 1, metadata: { php: '8.6.1', image: `php:8.6.1-fpm-trixie@sha256:${digest}`, lockSha256: digest, mysql: '9.7.4' },
        stages: Object.fromEntries(stages.map((name) => [name, { status: 'passed' }])) };
}

test('PHP 8.6 selection moves from published RC to stable without following PHP 8.7', () => {
    assert.deepEqual(selectRelease({ error: 'Unknown version' }, qa), { version: '8.6.0RC4', channel: 'RC' });
    assert.deepEqual(selectRelease(stable, qa), { version: '8.6.1', channel: 'stable' });
    assert.throws(() => selectRelease({ ...stable, version: '8.7.0' }, qa), /Unrecognized/);
    assert.throws(() => selectRelease({ error: 'network error' }, qa), /Unrecognized/);
    assert.throws(() => selectRelease({ error: 'Unknown version' }, { '8.7.0': qa['8.6.0'] }), /No published/);
    for (const change of [{ enabled: false }, { type: 'beta' }, { number: 0 }, { sha256_xz: '' }]) {
        assert.throws(() => selectRelease({ error: 'Unknown version' }, { '8.6.0': { active: true, release: { ...qa['8.6.0'].release, ...change } } }), /No published/);
    }
});

test('compatibility requires every stage and actual runtime evidence', () => {
    assert.deepEqual(resultOf(complete()), { status: 'compatible', label: 'compatible', blocker: '' });
    for (const stage of stages) {
        for (const status of ['pending', 'running', 'skipped', 'unavailable', 'cancelled', undefined]) {
            const report = complete();
            report.stages[stage] = { status };
            assert.equal(resultOf(report).status, 'unavailable');
        }
    }
    for (const missing of ['metadata', 'schemaVersion', 'stages']) {
        const report = complete(); delete report[missing];
        assert.equal(resultOf(report).status, 'unavailable');
    }
    const report = complete(); report.metadata.php = '8.5.12';
    assert.deepEqual(resultOf(report), { status: 'unavailable', label: 'unavailable – Evidence', blocker: 'evidence' });
});

test('first failed stage blocks every following stage and stays visible in the summary', () => {
    for (const name of stages) {
        const report = complete();
        report.stages[name] = { status: 'incompatible', reason: 'A package requires PHP <8.6.' };
        skipFollowing(report, name);
        assert.equal(resultOf(report).status, 'incompatible');
        assert.equal(resultOf(report).blocker, name);
        for (const later of stages.slice(stages.indexOf(name) + 1)) assert.equal(report.stages[later].status, 'skipped');
        assert.match(summaryOf(report), /PHP &lt;8.6/);
        if (name === 'environment') {
            assert.equal(resultOf(report).label, 'incompatible – Environment');
            assert.equal(report.stages.composer.reason, 'Blocked by Environment.');
            assert.match(summaryOf(report), /\| Environment \| incompatible \|/);
        }
    }
});

test('database pins are read from the development stack and never accept an older server', () => {
    const compose = readFileSync(new URL('../../docker-compose.dev.yml', import.meta.url), 'utf8');
    assert.match(runtimePins(compose).mysql, /^mysql:9\.7\./);
    assert.throws(() => runtimePins(compose.replace(/mysql:9\.7\.\d+/g, 'mysql:8.4.13')), /MySQL 9.7/);
    assert.throws(() => runtimePins(`${compose}\nimage: mysql:9.7.99@sha256:${digest}`), /one pinned/);
});

test('PHP wrappers select the isolated stack together and reject unsafe project names', () => {
    assert.deepEqual(composeArguments({}), ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml']);
    const environment = { ERNIE_PHP86_COMPOSE: 'docker-compose.php86.yml', ERNIE_PHP86_ENV_FILE: '.tmp/test.env', COMPOSE_PROJECT_NAME: 'ernie-php86-0123456789abcdef' };
    const args = composeArguments(environment);
    assert.equal(args[2], environment.COMPOSE_PROJECT_NAME);
    assert.ok(args.at(-1).endsWith('docker-compose.php86.yml'));
    for (const name of ['ernie', '', '../ernie-php86-0123456789abcdef']) assert.throws(() => composeArguments({ ...environment, COMPOSE_PROJECT_NAME: name }), /isolated/);
});

test('only JUnit evidence establishes a test failure, not a generic process error', () => {
    assert.equal(hasTestFailure('<testsuite failures="1"><testcase><failure message="failed"/></testcase></testsuite>'), true);
    assert.equal(hasTestFailure('<testsuite errors="2"/>'), true);
    assert.equal(hasTestFailure('<testsuite failures="0" errors="0"/>'), false);
    assert.equal(hasTestFailure('Docker daemon unavailable'), false);
});

test('Composer platform failures require structured stdout and retain the locked package constraint', () => {
    const failed = { name: 'php', version: '8.6.0RC4', status: 'failed', failed_requirement: { source: 'vendor/package', constraint: '<8.6' } };
    assert.deepEqual(platformFailures(JSON.stringify([failed, { name: 'ext-json', status: 'success' }])), [failed]);
    assert.equal(platformFailures(JSON.stringify([{ name: 'ext-redis', status: 'missing' }])).length, 1);
    for (const output of [undefined, 'Download failed', 'Checking platform requirements\n[]', '{}', '[]']) assert.deepEqual(platformFailures(output), []);
});

test('JSON-LD semantic failures are distinct from missing reports and transport failures', () => {
    const evidence = JSON.stringify({ type: 'jsonld-semantic-failure', message: 'JSON-LD contract: wrong resource type' });
    assert.equal(jsonLdFailure(`Pest passed\n${evidence}\n[jsonld] wrong resource type`), 'JSON-LD contract: wrong resource type');
    for (const log of ['[jsonld] Report missing', 'Docker daemon unavailable', 'null', '{"type":"jsonld-semantic-failure"}', '']) {
        assert.equal(jsonLdFailure(log), undefined);
    }
});

const phpstanFileFailure = {
    totals: { errors: 0, file_errors: 1 },
    files: { 'app/Example.php': { errors: 1, messages: [{ message: 'Call to an undefined method Example::missing().', line: 12, ignorable: true, identifier: 'method.notFound' }] } },
    errors: [],
};

test('PHPStan global analyzer errors never establish incompatibility, including errors-only reports', () => {
    for (const message of ['Child process error: Allowed memory size of 2147483648 bytes exhausted.', 'Child process error (exit code 255): worker crashed.']) {
        const errorsOnly = { totals: { errors: 1, file_errors: 0 }, files: {}, errors: [message] };
        assert.equal(hasPhpstanFailure(JSON.stringify(errorsOnly)), false, message);
        const mixed = { ...phpstanFileFailure, totals: { errors: 1, file_errors: 1 }, errors: [message] };
        assert.equal(hasPhpstanFailure(JSON.stringify(mixed)), false, 'A partial analysis with file diagnostics is still unavailable.');
        assert.equal(hasPhpstanFailure(JSON.stringify({ ...mixed, totals: { errors: 0, file_errors: 1 } })), false, 'Global diagnostics cannot be hidden by inconsistent totals.');
        assert.equal(hasPhpstanFailure(JSON.stringify({ ...mixed, errors: [] })), false, 'Global error totals cannot be hidden by missing diagnostics.');
    }
});

test('PHPStan incompatibility requires concrete file diagnostics without global analyzer errors', () => {
    const log = `Preparing PHPStan workspace\n${JSON.stringify(phpstanFileFailure)}\nPHPStan exited with code 1.\n`;
    assert.equal(hasPhpstanFailure(log), true);
    for (const files of [undefined, null, [], {}, { 'app/Example.php': { errors: 1, messages: [] } }, { 'app/Example.php': { errors: 1, messages: [{}] } }, { 'app/Example.php': { errors: 1, messages: [{ message: ' ' }] } }]) {
        assert.equal(hasPhpstanFailure(JSON.stringify({ ...phpstanFileFailure, files })), false);
    }
    for (const output of [undefined, '', 'Docker daemon unavailable', '{"totals":', '{"totals":null}', 'null', '[]', JSON.stringify({ totals: { errors: 0, file_errors: 0 }, files: {}, errors: [] })]) {
        assert.equal(hasPhpstanFailure(output), false);
    }
});

test('removed Zend APIs establish extension incompatibility while transport and memory failures do not', () => {
    const diagnostic = "/usr/src/php/ext/redis/redis.c:1273:9: error: implicit declaration of function 'ZEND_WRONG_PARAM_COUNT' [-Wimplicit-function-declaration]";
    assert.equal(extensionBuildFailure(diagnostic).extension, 'redis');
    for (const log of ['curl: (6) Could not resolve host', 'failed to solve: exit code: 2', `${diagnostic}\nKilled signal terminated program cc1`]) {
        assert.equal(extensionBuildFailure(log), undefined);
    }
});

test('the reporting entrypoint exits successfully and publishes an explicit GitHub status even for incompatibility or missing evidence', () => {
    const temporary = mkdtempSync(join(tmpdir(), 'ernie-php86-report-test-'));
    const runner = fileURLToPath(new URL('../../scripts/run-php86.mjs', import.meta.url));
    try {
        const malformed = { corrupt: '{', null: 'null', array: '[]', scalar: 'true' };
        for (const status of ['compatible', 'incompatible', 'unavailable', 'missing', ...Object.keys(malformed)]) {
            const directory = join(temporary, status);
            const output = join(temporary, `${status}-output`);
            const summary = join(temporary, `${status}-summary`);
            mkdirSync(directory);
            const report = complete();
            if (status === 'incompatible' || status === 'unavailable') {
                report.stages.composer = { status, reason: 'Locked package constraint or unavailable transport.' };
                skipFollowing(report, 'composer');
            }
            if (status !== 'missing') writeFileSync(join(directory, 'report.json'), malformed[status] ?? JSON.stringify(report));
            const result = spawnSync(process.execPath, [runner, '--report'], {
                env: { ...process.env, ERNIE_PHP86_REPORT_DIR: directory, GITHUB_OUTPUT: output, GITHUB_STEP_SUMMARY: summary }, encoding: 'utf8',
            });
            assert.equal(result.status, 0, result.stderr);
            const expected = status === 'missing' || status in malformed ? 'unavailable' : status;
            assert.match(readFileSync(output, 'utf8'), new RegExp(`status=${expected}\\n`));
            assert.equal(JSON.parse(readFileSync(join(directory, 'report.json'), 'utf8')).result.status, expected);
            const expectedLabel = expected === 'compatible' ? 'compatible'
                : expected === 'incompatible' ? 'incompatible – Composer'
                    : status === 'unavailable' ? 'unavailable – Composer' : 'unavailable – Environment';
            assert.ok(readFileSync(output, 'utf8').includes(`label=${expectedLabel}\n`));
            assert.ok(readFileSync(summary, 'utf8').startsWith(`# PHP 8.6: ${expectedLabel}\n`));
            assert.ok(result.stdout.includes(`PHP 8.6: ${expectedLabel}`));
            assert.match(readFileSync(summary, 'utf8'), /only \*\*compatible\*\* means every required stage passed/);
            if (expected !== 'compatible') assert.doesNotMatch(readFileSync(output, 'utf8'), /^label=compatible$/m);
        }
    } finally { rmSync(temporary, { recursive: true, force: true }); }
});
