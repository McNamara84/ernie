import { readFileSync } from 'node:fs';
import { setTimeout } from 'node:timers/promises';
import { pathToFileURL } from 'node:url';

import { verifyProjectCoverage } from './check-codecov-coverage.mjs';

export function latestPestRun(runs, { commit, event, branch, pullRequest, headRepository }) {
    return runs.filter((run) => run.head_sha === commit && run.event === event && run.head_branch === branch
        && (!pullRequest || run.pull_requests?.some((pr) => pr.number === Number(pullRequest))
            // GitHub can return an empty association even for a real PR run.
            // Only accept that fallback after independently verifying the PR.
            || (!run.pull_requests?.length && headRepository && run.head_repository?.full_name === headRepository)))
        .sort((a, b) => b.run_number - a.run_number || b.run_attempt - a.run_attempt)[0];
}

export async function checkCiProjectCoverage({
    repository, commit, event, branch, pullRequest, frontendResult, baseline,
    token, request = fetch, wait = setTimeout, now = Date.now, timeoutMs = 20 * 60_000,
}) {
    if (!/^[\w.-]+\/[\w.-]+$/u.test(repository ?? '') || !/^[a-f\d]{40}$/u.test(commit ?? '')
        || !['push', 'pull_request'].includes(event) || !branch || frontendResult !== 'success'
        || (event === 'pull_request' && !/^[1-9]\d*$/u.test(pullRequest ?? ''))) {
        throw new Error('Coverage audit requires the tested revision and successful frontend test shards.');
    }
    const started = now();
    const headers = { Accept: 'application/vnd.github+json', ...(token ? { Authorization: `Bearer ${token}` } : {}) };
    const query = new URLSearchParams({ head_sha: commit, event, per_page: '100' });
    async function json(url, requestHeaders) {
        const response = await request(url, { headers: requestHeaders, signal: AbortSignal.timeout(15_000) });
        if (response.status === 404 || response.status === 429 || response.status >= 500) return null;
        if (!response.ok) throw new Error(`Coverage prerequisite API returned HTTP ${response.status}.`);
        return response.json();
    }

    let headRepository;
    while (now() - started < timeoutMs) {
        if (event === 'pull_request' && !headRepository) {
            const pr = await json(`https://api.github.com/repos/${repository}/pulls/${pullRequest}`, headers);
            if (!pr) {
                await wait(30_000);
                continue;
            }
            if (pr.head?.sha !== commit || pr.head?.ref !== branch || pr.base?.repo?.full_name !== repository
                || !pr.head?.repo?.full_name) {
                throw new Error('The PR no longer matches the tested revision or repository.');
            }
            headRepository = pr.head.repo.full_name;
        }
        const runs = await json(`https://api.github.com/repos/${repository}/actions/workflows/tests.yml/runs?${query}`, headers);
        const pest = runs && latestPestRun(runs.workflow_runs ?? [], { commit, event, branch, pullRequest, headRepository });
        if (pest?.status === 'completed' && pest.conclusion !== 'success') {
            throw new Error(`Latest Pest workflow for this revision ${pest.conclusion}; refusing incomplete coverage.`);
        }
        if (pest?.status === 'completed' && pest.conclusion === 'success') {
            const report = await json(`https://api.codecov.io/api/v2/github/${repository.replace('/', '/repos/')}/commits/${commit}/`);
            const files = report?.report?.files;
            if (report?.state === 'complete' && report.totals?.sessions >= baseline.totals.sessions
                && Array.isArray(files) && files.some((file) => file.name?.startsWith('app/'))
                && files.some((file) => file.name?.startsWith('resources/js/'))) {
                return {
                    ...verifyProjectCoverage(report, baseline, commit, {
                        successfulSuites: { backend: pest.conclusion, frontend: frontendResult },
                    }),
                    pestRun: pest.html_url,
                };
            }
        }
        await wait(30_000);
    }
    throw new Error('Timed out waiting for successful Pest and complete aggregate coverage; the audit did not pass.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const result = await checkCiProjectCoverage({
            repository: process.env.GITHUB_REPOSITORY,
            commit: process.env.COVERAGE_COMMIT,
            event: process.env.GITHUB_EVENT_NAME,
            branch: process.env.SOURCE_BRANCH,
            pullRequest: process.env.PULL_REQUEST_NUMBER,
            frontendResult: process.env.FRONTEND_SUITE_RESULT,
            token: process.env.GITHUB_TOKEN,
            baseline: JSON.parse(readFileSync(new URL('../tests/coverage-baseline.json', import.meta.url), 'utf8')),
        });
        console.log(JSON.stringify(result, null, 2));
    } catch (error) {
        console.error(`[coverage] ${error.message}`);
        process.exitCode = 1;
    }
}
