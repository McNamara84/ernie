import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { availableParallelism, totalmem } from 'node:os';
import { dirname, resolve } from 'node:path';
import { performance } from 'node:perf_hooks';

export function resolveTestWorkers(configuredWorkers, availableCpus = availableParallelism()) {
    const configured = configuredWorkers?.trim();
    const workers = configured ? Number(configured) : Math.max(1, Math.min(8, Math.floor(availableCpus / 2)));

    if (!Number.isSafeInteger(workers) || workers < 1) {
        throw new Error('The worker override must be a positive integer.');
    }

    return workers;
}

// Opt-in reporting keeps ordinary runs quiet. Reports include failed phases so
// a fast failing run cannot accidentally be treated as a performance win.
export function createTestTimings(suite, { outputPath = process.env.ERNIE_TEST_TIMINGS_FILE, now = () => performance.now() } = {}) {
    const startedAt = now();
    const report = {
        schemaVersion: 1,
        suite,
        startedAt: new Date().toISOString(),
        status: 'running',
        resources: { availableCpus: availableParallelism(), totalMemoryBytes: totalmem() },
        phases: [],
    };

    return {
        report,
        measure(name, operation) {
            const phaseStart = now();
            const phase = { name, status: 'passed' };

            try {
                return operation();
            } catch (error) {
                phase.status = 'failed';
                throw error;
            } finally {
                phase.durationMs = now() - phaseStart;
                report.phases.push(phase);
            }
        },
        finish(exitCode = 0) {
            report.durationMs = now() - startedAt;
            report.exitCode = exitCode;
            report.status = exitCode === 0 ? 'passed' : 'failed';

            if (outputPath?.trim()) {
                const revision = spawnSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' });
                report.commit = revision.status === 0 ? revision.stdout.trim() : null;
                const changes = spawnSync('git', ['status', '--porcelain'], { encoding: 'utf8' });
                report.dirty = changes.status === 0 ? changes.stdout.trim().length > 0 : null;
                report.node = process.version;
                const output = resolve(outputPath);
                mkdirSync(dirname(output), { recursive: true });
                writeFileSync(output, `${JSON.stringify(report, null, 2)}\n`);
            }

            return report;
        },
    };
}
