import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync,writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { createGit, enableSyncMerge, reconcileSync, syncBranch, syncHeld } from '../../scripts/ci/sync-main-to-dev.mjs';

function fixture(t) {
    const root = mkdtempSync(join(tmpdir(), 'ernie-sync-test-'));
    t.after(() => rmSync(root, { recursive: true, force: true }));
    const remote = join(root, 'remote.git');
    const work = join(root, 'work'); mkdirSync(work);
    const run = (...args) => execFileSync('git', args, { cwd: work, encoding: 'utf8', stdio: 'pipe' }).trim();
    run('init', '--bare', remote);
    run('init', '-b', 'main');
    run('config', 'user.email', 'test@example.invalid'); run('config', 'user.name', 'Test');
    run('config', 'commit.gpgsign', 'false');
    const commit = (file, text) => { writeFileSync(join(work, file), text); run('add', file); run('commit', '-m', file); return run('rev-parse', 'HEAD'); };
    commit('shared.txt', 'base\n');
    run('remote', 'add', 'origin', remote); run('push', 'origin', 'main');
    run('switch', '-c', 'dev'); commit('feature.txt', 'feature\n'); run('push', 'origin', 'dev');
    run('switch', 'main'); commit('fix.txt', 'fix\n'); run('push', 'origin', 'main');
    const prs = [];
    let mutations = 0;
    const github = {
        paginate: async (_method, query) => prs.filter((pr) => pr.state === query.state),
        rest: {
            repos: { getBranch: async () => ({ data: { protected: true } }), get: async () => ({ data: { allow_auto_merge: true, allow_merge_commit: true } }) },
            pulls: {
                list: () => {},
                create: async (input) => {
                    const pr = { ...input, number: prs.length + 1, node_id: `pr-${prs.length + 1}`, state: 'open', draft: false, labels: [], auto_merge: null };
                    prs.push(pr); return { data: { ...pr } };
                },
                get: async ({ pull_number }) => ({ data: { ...prs[pull_number - 1] } }),
                update: async ({ pull_number, ...input }) => { Object.assign(prs[pull_number - 1], input); return { data: { ...prs[pull_number - 1] } }; },
            },
        },
        graphql: async (query, { id }) => {
            mutations++;
            prs.find((pr) => pr.node_id === id).auto_merge = query.includes('disablePullRequest') ? null : { merge_method: 'merge' };
        },
    };
    const git = createGit(work);
    return { run, commit, github, prs, mutations: () => mutations, options: { github, repo: { owner: 'Example', repo: 'ernie' }, git, core: { notice: () => {} } } };
}

test('sync creates one integration PR, preserves both histories, and does not churn unchanged commits', async (t) => {
    const f = fixture(t);
    const originalMain = f.run('rev-parse', 'main');
    await reconcileSync(f.options);
    const integration = f.run('ls-remote', 'origin', `refs/heads/${syncBranch}`).split(/\s/u)[0];
    assert.equal(f.prs.length, 1);
    assert.equal(f.prs[0].head, syncBranch);
    assert.equal(f.prs[0].base, 'dev');
    assert.ok(f.prs[0].auto_merge);
    f.run('merge-base', '--is-ancestor', 'main', integration);
    f.run('merge-base', '--is-ancestor', 'dev', integration);
    assert.equal(f.run('show', `${integration}:fix.txt`), 'fix');
    assert.equal(f.run('show', `${integration}:feature.txt`), 'feature');
    assert.equal(f.run('rev-parse', 'main'), originalMain);
    await reconcileSync(f.options);
    assert.equal(f.run('ls-remote', 'origin', `refs/heads/${syncBranch}`).split(/\s/u)[0], integration);
    assert.equal(f.prs.length, 1);
    assert.equal(f.mutations(), 1);
});

test('new source or target commits refresh the same PR for new CI without modifying main', async (t) => {
    const f = fixture(t);
    await reconcileSync(f.options);
    const main = f.commit('another-fix.txt', 'fix two\n'); f.run('push', 'origin', 'main');
    await reconcileSync(f.options);
    assert.ok(f.prs[0].body.includes(`Source: ${main}`));
    f.run('switch', 'dev'); const dev = f.commit('another-feature.txt', 'feature two\n'); f.run('push', 'origin', 'dev');
    await reconcileSync(f.options);
    assert.ok(f.prs[0].body.includes(`Target: ${dev}`));
    assert.equal(f.prs.length, 1);
    assert.equal(f.run('rev-parse', 'main'), main);
});

test('conflicts remain visible in a PR without committing conflict markers or changing main', async (t) => {
    const f = fixture(t);
    f.commit('shared.txt', 'main version\n'); f.run('push', 'origin', 'main');
    f.run('switch', 'dev'); f.commit('shared.txt', 'dev version\n'); f.run('push', 'origin', 'dev');
    const main = f.run('rev-parse', 'main');
    await assert.rejects(reconcileSync(f.options), /merge conflicts/u);
    assert.equal(f.prs.length, 1);
    assert.equal(f.prs[0].auto_merge, null);
    assert.equal(f.run('ls-remote', 'origin', `refs/heads/${syncBranch}`).split(/\s/u)[0], main);
    assert.equal(f.run('show', 'main:shared.txt'), 'main version');
});

test('maintainer holds and disabled auto-merge are not silently undone', async (t) => {
    const f = fixture(t);
    await reconcileSync(f.options);
    f.prs[0].auto_merge = null;
    f.commit('more.txt', 'later fix\n'); f.run('push', 'origin', 'main');
    const before = f.run('ls-remote', 'origin', `refs/heads/${syncBranch}`);
    assert.match((await reconcileSync(f.options)).message, /on hold/u);
    assert.equal(f.run('ls-remote', 'origin', `refs/heads/${syncBranch}`), before);
    assert.ok(syncHeld({ labels: [{ name: 'sync:hold' }] }));
    assert.ok(syncHeld({ draft: true }));
});

test('a helper merge closes completed work and allows future synchronization', async (t) => {
    const f = fixture(t);
    await reconcileSync(f.options);
    f.run('switch', 'dev'); f.run('merge', '--no-ff', 'main', '-m', 'Manually reviewed integration'); f.run('push', 'origin', 'dev');
    await reconcileSync(f.options);
    assert.equal(f.prs[0].state, 'closed');
    f.run('switch', 'main'); f.commit('later.txt', 'later fix\n'); f.run('push', 'origin', 'main');
    await reconcileSync(f.options);
    assert.equal(f.prs.length, 2);
    assert.equal(f.prs[1].state, 'open');
});

test('unprotected dev and unavailable auto-merge fail before writing a branch', async (t) => {
    const f = fixture(t);
    f.github.rest.repos.getBranch = async () => ({ data: { protected: false } });
    await assert.rejects(reconcileSync(f.options), /Protect dev/u);
    f.github.rest.repos.getBranch = async () => ({ data: { protected: true } });
    f.github.rest.repos.get = async () => ({ data: { allow_auto_merge: false, allow_merge_commit: true } });
    await assert.rejects(reconcileSync(f.options), /Enable auto-merge/u);
    assert.equal(f.prs.length, 0);
});

test('already mergeable PRs require approved successful checks and merge only the inspected head', async () => {
    const node = { state: 'OPEN', headRefOid: 'a'.repeat(40), mergeStateStatus: 'CLEAN', reviewDecision: 'APPROVED',
        commits: { nodes: [{ commit: { statusCheckRollup: { state: 'SUCCESS' } } }] } };
    let merged;
    const github = {
        graphql: async (query) => {
            if (query.startsWith('mutation')) throw new Error('Already ready');
            return { node };
        },
        rest: { pulls: { merge: async (input) => { merged = input; return { data: { merged: true } }; } } },
    };
    const repo = { owner: 'Example', repo: 'ernie' };
    const pr = { number: 1, node_id: 'pr-1' };
    assert.equal(await enableSyncMerge(github, repo, pr), true);
    assert.equal(merged.sha, node.headRefOid);
    assert.equal(merged.merge_method, 'merge');
    merged = null;
    node.reviewDecision = 'REVIEW_REQUIRED';
    await assert.rejects(enableSyncMerge(github, repo, pr), /Already ready/u);
    assert.equal(merged, null);
    node.reviewDecision = 'APPROVED';
    node.commits.nodes[0].commit.statusCheckRollup.state = 'FAILURE';
    await assert.rejects(enableSyncMerge(github, repo, pr), /Already ready/u);
    assert.equal(merged, null);
});
