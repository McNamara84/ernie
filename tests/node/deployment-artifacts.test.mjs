import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import test from 'node:test';

import { publishArtifact, readArtifact, requireProductionReview, validateArtifact } from '../../scripts/ci/deployment-artifacts.mjs';

const repo = { owner: 'Example', repo: 'ernie' };
const sha = 'a'.repeat(40);
const artifact = () => ({ schema: 1, repository: 'Example/ernie', branch: 'main', sha, version: '1.1.1', images: {
    app: `ghcr.io/example/ernie-app@sha256:${'1'.repeat(64)}`,
    nginx: `ghcr.io/example/ernie-nginx@sha256:${'2'.repeat(64)}`,
    fuji: `ghcr.io/example/ernie-fuji@sha256:${'3'.repeat(64)}`,
} });

function api() {
    const trees = new Map();
    const commits = new Map();
    const heads = new Map();
    let sequence = 0;
    let race;
    const fail = (status) => { throw Object.assign(new Error('API failure'), { status }); };
    const git = {
        getRef: async ({ ref }) => heads.has(ref) ? { data: { object: { sha: heads.get(ref) } } } : fail(404),
        getCommit: async ({ commit_sha }) => ({ data: commits.get(commit_sha) }),
        createTree: async ({ base_tree, tree }) => {
            const id = `tree-${++sequence}`;
            trees.set(id, { ...trees.get(base_tree), ...Object.fromEntries(tree.map((entry) => [entry.path, entry.content])) });
            return { data: { sha: id } };
        },
        createCommit: async (input) => {
            const id = `commit-${++sequence}`;
            commits.set(id, { ...input, tree: { sha: input.tree } });
            return { data: { sha: id } };
        },
        createRef: async ({ ref, sha: commit }) => {
            ref = ref.replace(/^refs\//u, '');
            if (heads.has(ref)) fail(422);
            heads.set(ref, commit);
        },
        updateRef: async ({ ref, sha: commit, force }) => {
            assert.equal(force, false);
            if (race) { const action = race; race = undefined; await action(); }
            if (commits.get(commit).parents[0] !== heads.get(ref)) fail(422);
            heads.set(ref, commit);
        },
    };
    return {
        github: { rest: { git, repos: { getContent: async ({ path, ref }) => {
            const content = trees.get(commits.get(ref).tree.sha)[path];
            if (!content) fail(404);
            return { data: { type: 'file', encoding: 'base64', content: Buffer.from(content).toString('base64') } };
        } } } }, heads, commits, race: (action) => { race = action; },
    };
}

test('artifact validation rejects wrong provenance, mutable images and release version drift', () => {
    assert.equal(validateArtifact(artifact(), { sha, branch: 'main', version: '1.1.1' }).sha, sha);
    for (const expected of [{ sha: 'b'.repeat(40) }, { branch: 'dev' }, { version: '1.1.2' }, { repository: 'attacker/ernie' }]) {
        assert.throws(() => validateArtifact(artifact(), expected), /does not match/u);
    }
    for (const image of ['ghcr.io/example/ernie-app:latest', `ghcr.io/attacker/ernie-app@sha256:${'1'.repeat(64)}`]) {
        const record = artifact(); record.images.app = image;
        assert.throws(() => validateArtifact(record), /digest reference/u);
    }
});

test('artifact publication preserves earlier commits and reuses the first image set on retry', async () => {
    const { github, heads } = api();
    const first = artifact();
    await publishArtifact(github, repo, first);
    const second = { ...artifact(), sha: 'b'.repeat(40) };
    await publishArtifact(github, repo, second);
    assert.deepEqual((await readArtifact(github, repo, 'main', sha)).record, first);
    const head = heads.get('heads/artifacts/main');
    assert.equal(await publishArtifact(github, repo, first), head);
    const changed = artifact(); changed.images.app = `ghcr.io/example/ernie-app@sha256:${'4'.repeat(64)}`;
    await assert.rejects(publishArtifact(github, repo, changed), /immutable/u);
    assert.equal(heads.get('heads/artifacts/main'), head);
    assert.equal(await readArtifact(github, repo, 'dev', sha), null);
});

test('concurrent publication retries without overwriting another source record', async () => {
    const mock = api();
    await publishArtifact(mock.github, repo, artifact());
    const second = { ...artifact(), sha: 'b'.repeat(40) };
    const third = { ...artifact(), sha: 'c'.repeat(40) };
    mock.race(() => publishArtifact(mock.github, repo, third));
    await publishArtifact(mock.github, repo, second);
    for (const record of [artifact(), second, third]) assert.deepEqual((await readArtifact(mock.github, repo, 'main', record.sha)).record, record);
});

test('publication cannot replace a concurrent record for the same source SHA', async () => {
    const mock = api();
    await publishArtifact(mock.github, repo, artifact());
    const second = { ...artifact(), sha: 'b'.repeat(40) };
    const changed = structuredClone(second); changed.images.fuji = `ghcr.io/example/ernie-fuji@sha256:${'4'.repeat(64)}`;
    mock.race(() => publishArtifact(mock.github, repo, second));
    await assert.rejects(publishArtifact(mock.github, repo, changed), /immutable/u);
    assert.deepEqual((await readArtifact(mock.github, repo, 'main', second.sha)).record, second);
});

test('Production promotion fails closed without an environment reviewer', () => {
    for (const environment of [{}, { protection_rules: [] }, { protection_rules: [{ type: 'required_reviewers', reviewers: [] }] }]) {
        assert.throws(() => requireProductionReview(environment), /required reviewers/u);
    }
    requireProductionReview({ protection_rules: [{ type: 'required_reviewers', reviewers: [{ id: 1 }] }] });
});
