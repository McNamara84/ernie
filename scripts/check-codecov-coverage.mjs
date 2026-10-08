import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

function validateTotals(totals) {
    for (const field of ['lines', 'hits', 'misses', 'partials', 'sessions']) {
        if (!Number.isSafeInteger(totals?.[field]) || totals[field] < 0) {
            throw new Error(`Invalid coverage counter: ${field}.`);
        }
    }

    if (totals.lines === 0 || totals.hits + totals.misses + totals.partials !== totals.lines) {
        throw new Error('Coverage counters do not describe a complete, non-empty report.');
    }
}

export function verifyProjectCoverage(report, baseline, expectedCommit, { successfulSuites } = {}) {
    if (!expectedCommit || report.commitid !== expectedCommit) {
        throw new Error('Coverage report belongs to a different commit.');
    }

    // An audit inside Vitest cannot wait for its own workflow to finish. That
    // caller must independently verify Pest and its own completed test/merge
    // steps; the manual audit continues to require Codecov's CI result.
    const suitesPassed = successfulSuites?.backend === 'success' && successfulSuites?.frontend === 'success';
    if (report.state !== 'complete' || (report.ci_passed !== true && !suitesPassed)) {
        throw new Error('Coverage requires a complete report and successful CI.');
    }

    validateTotals(baseline.totals);
    validateTotals(report.totals);

    const files = report.report?.files;
    if (report.totals.sessions < baseline.totals.sessions || !Array.isArray(files)
        || !files.some((file) => file.name?.startsWith('app/'))
        || !files.some((file) => file.name?.startsWith('resources/js/'))) {
        throw new Error('Coverage must include fresh backend and frontend reports.');
    }

    // Compare integer ratios: rounded percentages can hide a one-line loss.
    if (BigInt(report.totals.hits) * BigInt(baseline.totals.lines)
        < BigInt(baseline.totals.hits) * BigInt(report.totals.lines)) {
        throw new Error('Overall project coverage is below the fixed refactoring baseline.');
    }

    return {
        commit: report.commitid,
        coveredLines: report.totals.hits,
        totalLines: report.totals.lines,
        coverage: (100 * report.totals.hits / report.totals.lines).toFixed(8),
        baselineCommit: baseline.commit,
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const { values } = parseArgs({ options: {
            report: { type: 'string' },
            commit: { type: 'string', default: process.env.GITHUB_SHA },
            baseline: { type: 'string' },
        } });
        if (!values.report || !values.commit) {
            throw new Error('Usage: --report <Codecov API JSON> --commit <expected SHA> [--baseline <JSON>].');
        }
        const readJson = (path) => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
        const baseline = readJson(values.baseline ?? new URL('../tests/coverage-baseline.json', import.meta.url));
        const result = verifyProjectCoverage(readJson(values.report), baseline, values.commit);
        console.log(JSON.stringify(result, null, 2));
    } catch (error) {
        console.error(`[coverage] ${error.message}`);
        process.exitCode = 1;
    }
}
