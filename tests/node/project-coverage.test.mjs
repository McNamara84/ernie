import assert from 'node:assert/strict';
import { test } from 'node:test';

import { verifyProjectCoverage } from '../../scripts/check-codecov-coverage.mjs';

const baseline = {
    commit: 'baseline',
    totals: { lines: 100000, hits: 90001, misses: 9999, partials: 0, sessions: 2 },
};
const report = () => ({
    commitid: 'current',
    state: 'complete',
    ci_passed: true,
    totals: { ...baseline.totals },
    report: { files: [{ name: 'app/Example.php' }, { name: 'resources/js/example.ts' }] },
});

test('an equal complete project report passes the fixed baseline', () => {
    assert.equal(verifyProjectCoverage(report(), baseline, 'current').coveredLines, 90001);
});

test('a single lost line fails even when a two-decimal percentage would look unchanged', () => {
    const current = report();
    current.totals.hits--;
    current.totals.misses++;
    assert.throws(() => verifyProjectCoverage(current, baseline, 'current'), /below/);
});

test('the gate compares aggregate ratios and permits coverage to shift between areas', () => {
    const current = report();
    current.report.files[0].totals = { hits: 10, misses: 90 };
    current.report.files[1].totals = { hits: 89991, misses: 9909 };
    assert.doesNotThrow(() => verifyProjectCoverage(current, baseline, 'current'));
    current.totals = { lines: 200000, hits: 180002, misses: 19998, partials: 0, sessions: 2 };
    assert.doesNotThrow(() => verifyProjectCoverage(current, baseline, 'current'));
});

test('pending CI, stale commits, missing uploads and missing language reports fail', () => {
    for (const mutate of [
        (current) => { current.state = 'pending'; },
        (current) => { current.ci_passed = false; },
        (current) => { current.commitid = 'stale'; },
        (current) => { current.totals.sessions = 1; },
        (current) => { current.report.files.pop(); },
        (current) => { current.report.files.shift(); },
    ]) {
        const current = report();
        mutate(current);
        assert.throws(() => verifyProjectCoverage(current, baseline, 'current'));
    }
});

test('empty, inconsistent and unsafe numeric counters fail', () => {
    for (const mutate of [
        (current) => { current.totals.lines = 0; },
        (current) => { current.totals.hits = -1; },
        (current) => { current.totals.hits = 1.5; },
        (current) => { current.totals.lines = Number.MAX_SAFE_INTEGER + 1; },
        (current) => { current.totals.misses--; },
    ]) {
        const current = report();
        mutate(current);
        assert.throws(() => verifyProjectCoverage(current, baseline, 'current'));
    }
});
