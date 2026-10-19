import { execFileSync, spawnSync } from 'node:child_process';

export const syncBranch = 'automation/sync-main-to-dev';
const marker = '<!-- ernie-main-dev-sync -->';
const armed = '<!-- auto-merge-armed -->';
const repositoryPaused = '<!-- auto-merge-paused-by-repository -->';

export function syncHeld(pr) {
    return pr.draft || pr.labels?.some((label) => label.name === 'sync:hold')
        || (pr.body?.includes(armed) && !pr.auto_merge && !pr.body.includes(repositoryPaused));
}

async function disableSyncMerge(github, repo, pr, { resumable = false } = {}) {
    let body = pr.body;
    if (pr.auto_merge) {
        await github.graphql('mutation($id: ID!) { disablePullRequestAutoMerge(input: {pullRequestId: $id}) { clientMutationId } }', { id: pr.node_id });
        if (!body.includes(armed)) body = `${body}\n${armed}`;
        if (resumable && !body.includes(repositoryPaused)) body = `${body}\n${repositoryPaused}`;
    }
    // An explicit PR hold takes precedence over an earlier repository pause.
    // Never turn a pre-existing manual disablement into a resumable pause.
    if (!resumable) body = body.replaceAll(`\n${repositoryPaused}`, '');
    if (body !== pr.body) await github.rest.pulls.update({ ...repo, pull_number: pr.number, body });
}

function syncDescription(description, pr) {
    return [description, ...[armed, repositoryPaused].filter((tag) => pr?.body?.includes(tag))].join('\n');
}

export async function enableSyncMerge(github, repo, pr) {
    try {
        await github.graphql('mutation($id: ID!) { enablePullRequestAutoMerge(input: {pullRequestId: $id, mergeMethod: MERGE}) { clientMutationId } }', { id: pr.node_id });
        return false;
    } catch (error) {
        // GitHub can reject auto-merge when a PR is already ready to merge.
        // Only merge that exact reviewed, successful head; branch rules still apply.
        const { node } = await github.graphql(`query($id: ID!) {
            node(id: $id) { ... on PullRequest {
                state headRefOid mergeStateStatus reviewDecision
                commits(last: 1) { nodes { commit { statusCheckRollup { state } } } }
            } }
        }`, { id: pr.node_id });
        if (node?.state !== 'OPEN' || node.mergeStateStatus !== 'CLEAN' || node.reviewDecision !== 'APPROVED'
            || node.commits.nodes[0]?.commit.statusCheckRollup?.state !== 'SUCCESS') throw error;
        const { data } = await github.rest.pulls.merge({ ...repo, pull_number: pr.number, sha: node.headRefOid, merge_method: 'merge' });
        if (!data.merged) throw new Error(`Synchronization PR #${pr.number} could not be merged: ${data.message}`, { cause: error });
        return true;
    }
}

export function createGit(cwd = process.cwd()) {
    const run = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }).trim();
    return {
        run,
        contains: (base, head) => {
            const result = spawnSync('git', ['merge-base', '--is-ancestor', base, head], { cwd });
            if (result.error || ![0, 1].includes(result.status)) throw result.error ?? new Error('Cannot compare synchronization history.');
            return result.status === 0;
        },
        merge: (base, head) => {
            const result = spawnSync('git', ['merge-tree', '--write-tree', base, head], { cwd, encoding: 'utf8' });
            if (result.error || ![0, 1].includes(result.status)) throw result.error ?? new Error('Cannot prepare synchronization merge.');
            return result.status === 0 ? result.stdout.split('\n')[0].trim() : null;
        },
    };
}

export async function reconcileSync({ github, repo, git = createGit(), core, paused = false }) {
    const notice = (message) => { core.notice(message); return { message }; };
    const prs = await github.paginate(github.rest.pulls.list, {
        ...repo, state: 'open', base: 'dev', head: `${repo.owner}:${syncBranch}`, per_page: 100,
    });
    if (prs.length > 1) throw new Error('More than one synchronization PR exists.');
    let pr = prs[0];
    if (pr && !pr.body?.includes(marker)) throw new Error('The synchronization branch is owned by an unmanaged PR.');
    if (paused) {
        if (pr) await disableSyncMerge(github, repo, pr, { resumable: !syncHeld(pr) });
        return notice('Synchronization paused by SYNC_MAIN_TO_DEV_PAUSED; managed auto-merge is disabled.');
    }
    if (pr && syncHeld(pr)) {
        await disableSyncMerge(github, repo, pr);
        return notice(`Synchronization PR #${pr.number} is on hold. Remove sync:hold, mark ready, and re-enable auto-merge to resume.`);
    }
    let target;
    try { target = (await github.rest.repos.getBranch({ ...repo, branch: 'dev' })).data; }
    catch (error) { if (error.status === 404) return notice('dev does not exist yet.'); throw error; }
    if (!target.protected) throw new Error('Protect dev with required reviews and checks before enabling synchronization.');
    const settings = (await github.rest.repos.get({ ...repo })).data;
    if (!settings.allow_auto_merge || !settings.allow_merge_commit) throw new Error('Enable auto-merge and merge commits in repository settings.');

    git.run('fetch', '--no-tags', 'origin', '+refs/heads/main:refs/remotes/origin/main', '+refs/heads/dev:refs/remotes/origin/dev');
    const main = git.run('rev-parse', 'refs/remotes/origin/main');
    const dev = git.run('rev-parse', 'refs/remotes/origin/dev');
    if (git.contains(main, dev)) {
        if (pr) await github.rest.pulls.update({ ...repo, pull_number: pr.number, state: 'closed', body: `${pr.body}\n<!-- sync-completed -->` });
        return notice('dev already includes main.');
    }

    const oldHead = git.run('ls-remote', '--heads', 'origin', `refs/heads/${syncBranch}`).split(/\s/u)[0];
    if (!pr) {
        const closed = await github.paginate(github.rest.pulls.list, {
            ...repo, state: 'closed', base: 'dev', head: `${repo.owner}:${syncBranch}`, per_page: 100,
        });
        if (closed.some((item) => item.body?.includes(marker) && !item.body.includes('<!-- sync-completed -->') && !item.merged_at)) {
            return notice('A maintainer closed a synchronization PR. Reopen it to resume.');
        }
    }

    const tree = git.merge(dev, main);
    const description = `${marker}\n\nSynchronize reviewed main changes into dev.\n\nSource: ${main}\nTarget: ${dev}\n\n${tree ? 'The integration branch preserves both histories. Required reviews and CI apply.' : 'Merge conflicts need resolution on a helper branch based on dev. Never merge dev into main to resolve this PR.'}\n\nAdd \`sync:hold\`, mark this PR as draft, or disable auto-merge to pause.\n`;
    // Reuse an unchanged integration commit so scheduled reconciliation does not
    // invalidate reviews or restart the full suite every fifteen minutes.
    const unchanged = pr?.body?.includes(`Source: ${main}\nTarget: ${dev}\n`);
    if (!unchanged) {
        if (git.run('ls-remote', '--heads', 'origin', 'refs/heads/main').split(/\s/u)[0] !== main
            || git.run('ls-remote', '--heads', 'origin', 'refs/heads/dev').split(/\s/u)[0] !== dev) {
            return notice('Branches advanced during preparation; the next reconciliation will retry.');
        }
        git.run('config', 'user.name', 'ERNIE branch sync');
        git.run('config', 'user.email', 'ernie-branch-sync@users.noreply.github.com');
        const next = tree ? git.run('commit-tree', tree, '-p', dev, '-p', main, '-m', `Synchronize main ${main} into dev ${dev}`) : main;
        git.run('push', `--force-with-lease=refs/heads/${syncBranch}:${oldHead}`, 'origin', `${next}:refs/heads/${syncBranch}`);
        if (pr) {
            await github.rest.pulls.update({ ...repo, pull_number: pr.number, body: syncDescription(description, pr) });
        }
    }
    if (!pr) {
        pr = (await github.rest.pulls.create({ ...repo, head: syncBranch, base: 'dev', title: 'Sync main into dev', body: description })).data;
    }
    pr = (await github.rest.pulls.get({ ...repo, pull_number: pr.number })).data;
    if (syncHeld(pr)) {
        await disableSyncMerge(github, repo, pr);
        return notice('Synchronization held before enabling auto-merge.');
    }
    if (!tree) {
        if (pr.auto_merge) await github.graphql('mutation($id: ID!) { disablePullRequestAutoMerge(input: {pullRequestId: $id}) { clientMutationId } }', { id: pr.node_id });
        // This is an automation block, not a maintainer hold. Keep it resumable.
        await github.rest.pulls.update({ ...repo, pull_number: pr.number, body: description });
        throw new Error(`Synchronization PR #${pr.number} has merge conflicts.`);
    }
    if (!pr.auto_merge) {
        if (await enableSyncMerge(github, repo, pr)) return notice(`Synchronization PR #${pr.number} merged after required reviews and CI.`);
    }
    const enabledBody = `${description}\n${armed}`;
    if (pr.body !== enabledBody) await github.rest.pulls.update({ ...repo, pull_number: pr.number, body: enabledBody });
    return notice(`Synchronization PR #${pr.number}: auto-merge waits for required reviews and CI.`);
}
