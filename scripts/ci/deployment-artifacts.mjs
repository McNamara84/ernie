import { Buffer } from 'node:buffer';

const shaPattern = /^[a-f0-9]{40}$/u;
const digestPattern = /^sha256:[a-f0-9]{64}$/u;
export const imageKinds = ['app', 'nginx', 'fuji'];

export function validateArtifact(record, { repository, branch, sha, version } = {}) {
    if (!record || record.schema !== 1 || !['main', 'dev'].includes(record.branch)
        || !shaPattern.test(record.sha) || !/^[\w.-]+\/[\w.-]+$/u.test(record.repository)
        || typeof record.version !== 'string' || !/^\d+\.\d+\.\d+[\w.+-]*$/u.test(record.version)) {
        throw new Error('Invalid deployment artifact record.');
    }
    for (const [key, expected] of Object.entries({ repository, branch, sha, version })) {
        if (expected !== undefined && record[key] !== expected) throw new Error(`Artifact ${key} does not match the candidate.`);
    }
    const owner = record.repository.split('/')[0].toLowerCase();
    for (const kind of imageKinds) {
        const prefix = `ghcr.io/${owner}/ernie-${kind}@`;
        const ref = record.images?.[kind];
        if (typeof ref !== 'string' || !ref.startsWith(prefix) || !digestPattern.test(ref.slice(prefix.length))) {
            throw new Error(`Invalid ${kind} digest reference in artifact record.`);
        }
    }
    return record;
}

function selection(repo, branch, sha) {
    if (!['main', 'dev'].includes(branch) || !shaPattern.test(sha)) throw new Error('Invalid artifact source.');
    return { repository: `${repo.owner}/${repo.repo}`, branch, sha };
}

async function artifactSnapshot(github, repo, branch, sha) {
    const expected = selection(repo, branch, sha);
    let head;
    try {
        head = (await github.rest.git.getRef({ ...repo, ref: `heads/artifacts/${branch}` })).data.object.sha;
    } catch (error) {
        if (error.status === 404) return { record: null, commit: undefined };
        throw error;
    }
    try {
        const { data } = await github.rest.repos.getContent({ ...repo, path: `${sha}.json`, ref: head });
        if (data.type !== 'file' || data.encoding !== 'base64') throw new Error('Invalid artifact file.');
        return { record: validateArtifact(JSON.parse(Buffer.from(data.content, 'base64').toString('utf8')), expected), commit: head };
    } catch (error) {
        if (error.status === 404) return { record: null, commit: head };
        throw error;
    }
}

export async function readArtifact(github, repo, branch, sha) {
    const snapshot = await artifactSnapshot(github, repo, branch, sha);
    return snapshot.record ? snapshot : null;
}

// The Git tree is append-only: retries reuse the first validated image set for a
// source SHA. A non-fast-forward ref update fails instead of losing another run.
export async function publishArtifact(github, repo, record) {
    validateArtifact(record, selection(repo, record.branch, record.sha));
    for (let attempt = 0; attempt < 3; attempt++) {
        const existing = await artifactSnapshot(github, repo, record.branch, record.sha);
        if (existing.record) {
            if (JSON.stringify(existing.record) !== JSON.stringify(record)) throw new Error('Refusing to replace immutable deployment artifacts.');
            return existing.commit;
        }
        const previous = existing.commit;
        const baseTree = previous ? (await github.rest.git.getCommit({ ...repo, commit_sha: previous })).data.tree.sha : undefined;
        const tree = await github.rest.git.createTree({
            ...repo, ...(baseTree ? { base_tree: baseTree } : {}),
            tree: [{ path: `${record.sha}.json`, mode: '100644', type: 'blob', content: `${JSON.stringify(record, null, 2)}\n` }],
        });
        const commit = await github.rest.git.createCommit({
            ...repo, message: `Record ${record.branch} images from ${record.sha}`,
            tree: tree.data.sha, parents: [...new Set([previous, record.sha].filter(Boolean))],
        });
        try {
            if (previous) {
                await github.rest.git.updateRef({ ...repo, ref: `heads/artifacts/${record.branch}`, sha: commit.data.sha, force: false });
            } else {
                await github.rest.git.createRef({ ...repo, ref: `refs/heads/artifacts/${record.branch}`, sha: commit.data.sha });
            }
            return commit.data.sha;
        } catch (error) {
            if (![409, 422].includes(error.status) || attempt === 2) throw error;
        }
    }
    throw new Error('Could not publish deployment artifact record.');
}

export function artifactOutputs(core, artifact) {
    core.setOutput('exists', artifact ? 'true' : 'false');
    if (!artifact) return;
    for (const kind of imageKinds) core.setOutput(`${kind}_digest`, artifact.record.images[kind].split('@')[1]);
    core.setOutput('artifact_commit', artifact.commit);
}
