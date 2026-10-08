import assert from 'node:assert/strict';
import test from 'node:test';

import { checkCiProjectCoverage, latestPestRun } from '../../scripts/check-ci-project-coverage.mjs';

const commit = 'a'.repeat(40);
const selection = { commit, event: 'pull_request', branch: 'refactor', pullRequest: '12' };
const run = { head_sha: commit, event: 'pull_request', head_branch: 'refactor', pull_requests: [{ number: 12 }], run_number: 3, run_attempt: 1, status: 'completed', conclusion: 'success' };
const baseline = { commit, totals: { lines: 10, hits: 8, misses: 2, partials: 0, sessions: 2 } };
const coverage = { commitid: commit, state: 'complete', ci_passed: false, totals: baseline.totals, report: { files: [{ name: 'app/Foo.php' }, { name: 'resources/js/foo.ts' }] } };
const pr = { head: { sha: commit, ref: selection.branch, repo: { full_name: 'contributor/repo' } }, base: { repo: { full_name: 'owner/repo' } } };

test('latest run selection never hides a failed rerun behind an older success or another PR', () => {
    const failed = { ...run, run_attempt: 2, conclusion: 'failure' };
    assert.equal(latestPestRun([run, failed, { ...run, run_number: 4, pull_requests: [{ number: 13 }] }], selection), failed);
    assert.equal(latestPestRun([{ ...run, head_sha: 'b'.repeat(40) }], selection), undefined);
});

function options(replies) {
    let clock = 0;
    const urls = [];
    replies = [pr, ...replies];
    return {
        urls,
        args: {
            ...selection, repository: 'owner/repo', frontendResult: 'success', baseline,
            now: () => clock, timeoutMs: 90_000,
            wait: () => { clock += 30_000; },
            request: async (url) => {
                urls.push(url);
                const body = replies.shift();
                return { ok: true, status: 200, json: async () => body };
            },
        },
    };
}

test('waits for the backend and both uploads without waiting on its own running workflow', async () => {
    const { args, urls } = options([
        { workflow_runs: [{ ...run, status: 'in_progress' }] },
        { workflow_runs: [run] }, { ...coverage, totals: { ...coverage.totals, sessions: 1 } },
        { workflow_runs: [run] }, coverage,
    ]);
    assert.equal((await checkCiProjectCoverage(args)).coveredLines, 8);
    assert.equal(urls.length, 6);
    assert.ok(urls.at(-1).includes(`/github/owner/repos/repo/commits/${commit}/`));
});

test('empty GitHub PR associations require the independently verified head repository', async () => {
    const unassociated = { ...run, pull_requests: [], head_repository: { full_name: 'contributor/repo' } };
    assert.equal(latestPestRun([unassociated], selection), undefined);
    assert.equal(latestPestRun([unassociated], { ...selection, headRepository: 'contributor/repo' }), unassociated);
    assert.equal(latestPestRun([unassociated], { ...selection, headRepository: 'another/repo' }), undefined);
    const verified = options([{ workflow_runs: [unassociated] }, coverage]);
    assert.equal((await checkCiProjectCoverage(verified.args)).coveredLines, 8);
    const changed = options([]);
    changed.args.commit = 'b'.repeat(40);
    await assert.rejects(checkCiProjectCoverage(changed.args), /PR no longer matches/u);
});

test('failed providers, one lost line, and incomplete reports fail the required check', async () => {
    const failed = options([{ workflow_runs: [{ ...run, conclusion: 'failure' }] }]);
    await assert.rejects(checkCiProjectCoverage(failed.args), /refusing incomplete/u);
    const lost = options([{ workflow_runs: [run] }, { ...coverage, totals: { ...coverage.totals, hits: 7, misses: 3 } }]);
    await assert.rejects(checkCiProjectCoverage(lost.args), /below the fixed/u);
    const absent = options([{ workflow_runs: [] }, { workflow_runs: [] }, { workflow_runs: [] }]);
    await assert.rejects(checkCiProjectCoverage(absent.args), /did not pass/u);
    await assert.rejects(checkCiProjectCoverage({ ...failed.args, frontendResult: 'failure' }), /successful frontend/u);
});
