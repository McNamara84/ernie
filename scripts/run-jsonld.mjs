import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { verifyContextChecksums, verifyReport } from './jsonld-contract.mjs';

const compose = ['compose', '--env-file', '.env.docker', '-f', 'docker-compose.dev.yml'];

function execute(command, args, environment) {
    const result = spawnSync(command, args, { stdio: 'inherit', env: environment });
    if (result.error || result.signal || result.status !== 0) {
        const error = result.error ?? new Error(`${command} exited with ${result.signal ?? result.status}.`);
        error.exitCode = result.status ?? 1;
        throw error;
    }
}

export async function runJsonLd({
    ci = process.env.GITHUB_ACTIONS === 'true',
    token = randomBytes(16).toString('hex'),
    run = execute,
    checkSnapshots = verifyContextChecksums,
    readReport = (path) => JSON.parse(readFileSync(path, 'utf8')),
    validateReport = verifyReport,
    removeReport = (path) => rmSync(path, { force: true }),
    log = console.log,
} = {}) {
    if (!/^[a-f0-9]{32}$/.test(token)) throw new Error('Invalid JSON-LD report token.');
    checkSnapshots();
    const temporaryDirectory = mkdtempSync(join(tmpdir(), 'ernie-jsonld-'));
    const fileName = `jsonld-${token}.json`;
    const containerWorkspace = `/tmp/ernie-jsonld-${token}`;
    const environment = { ...process.env, ERNIE_JSONLD_REPORT_TOKEN: token };
    const testArgs = ['tests/pest/Feature/JsonLdContractTest.php', '--compact'];
    const reportPath = ci ? join('storage', 'framework', 'testing', fileName) : join(temporaryDirectory, fileName);
    let failure;
    let result;
    try {
        if (ci) {
            run('php', ['-d', 'memory_limit=2G', './vendor/bin/pest', '--no-coverage', ...testArgs], environment);
        } else {
            run(process.execPath, ['scripts/run-pest.mjs', ...testArgs], environment);
            run('docker', [...compose, 'cp', `app:${containerWorkspace}/storage/framework/testing/${fileName}`, reportPath], environment);
        }
        result = await validateReport(readReport(reportPath));
        log(
            `[jsonld] ${result.dataCiteDocuments} DataCite and ${result.schemaOrgDocuments} Schema.org documents passed offline semantic validation.`,
        );
    } catch (error) {
        failure = error;
    } finally {
        try {
            if (ci) {
                removeReport(reportPath);
            } else {
                run('docker', [...compose, 'exec', '-T', 'app', 'rm', '-rf', '--', containerWorkspace], environment);
            }
        } catch (error) {
            if (failure) log(`[jsonld] Test workspace cleanup failed: ${error.message}`);
            else failure = error;
        } finally {
            rmSync(temporaryDirectory, { recursive: true, force: true });
        }
    }
    if (failure) throw failure;
    return result;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        await runJsonLd();
    } catch (error) {
        console.error(`[jsonld] ${error.message}`);
        process.exitCode = error.exitCode ?? 1;
    }
}
