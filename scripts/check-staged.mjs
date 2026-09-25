import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, extname, matchesGlob, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const npmCli = process.env.npm_execpath;
if (!npmCli) {
    console.error('Run this check with npm run precommit:check.');
    process.exit(1);
}
const lintExtensions = new Set(['.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx', '.mts', '.cts']);
const formatExtensions = new Set([...lintExtensions, '.css', '.scss', '.less', '.json', '.jsonc', '.md', '.mdx', '.html', '.vue', '.yml', '.yaml']);

function git(args) {
    const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
    if (result.error || result.status !== 0) {
        console.error(result.stderr || result.error?.message || `git exited with status ${result.status}`);
        process.exit(1);
    }

    return result.stdout;
}

function run(command, args, label) {
    console.log(`[pre-commit] ${label}`);
    const result = spawnSync(command, args, { cwd: root, stdio: 'inherit' });
    if (result.error || result.status !== 0) {
        if (result.error) console.error(result.error.message);
        process.exit(result.status || 1);
    }
}

function runNpm(args, label) {
    run(process.execPath, [npmCli, ...args], label);
}

function paths(output) {
    return output.split('\0').filter(Boolean);
}

const staged = paths(git(['diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z']));
if (staged.length === 0) {
    console.log('[pre-commit] No staged files to check.');
    process.exit(0);
}

run('git', ['diff', '--cached', '--check'], 'Checking staged whitespace');

const pintConfigChanged = staged.includes('pint.json');
const lintConfigChanged = staged.includes('.oxlintrc.json');
const formatConfigChanged = staged.includes('.oxfmtrc.json');
const php = staged.filter((path) => path.endsWith('.php') && !path.startsWith('resources/views/'));
const lint = staged.filter((path) => lintExtensions.has(extname(path)));
const formatIgnorePatterns = JSON.parse(readFileSync(resolve(root, '.oxfmtrc.json'), 'utf8')).ignorePatterns ?? [];
const format = staged.filter(
    (path) =>
        path.startsWith('resources/') && formatExtensions.has(extname(path)) && !formatIgnorePatterns.some((pattern) => matchesGlob(path, pattern)),
);
const openapiChanged = staged.includes('resources/data/openapi.json');
const checked = new Set([...php, ...lint, ...format]);
if (pintConfigChanged || php.length > 0) checked.add('pint.json');
if (lintConfigChanged || lint.length > 0) checked.add('.oxlintrc.json');
if (formatConfigChanged || format.length > 0) checked.add('.oxfmtrc.json');
if (checked.size > 0 || openapiChanged) checked.add('package.json');

// The tools read files from the worktree. Reject partially staged files so they
// cannot validate content different from what Git is about to commit.
const unstaged = new Set(paths(git(['diff', '--name-only', '-z'])));
const partiallyStaged = [...checked].filter((path) => unstaged.has(path));
if (partiallyStaged.length > 0) {
    console.error('[pre-commit] These checked files also have unstaged changes. Stage or set aside those changes first:');
    for (const path of partiallyStaged) console.error(`  ${path}`);
    process.exit(1);
}

if (pintConfigChanged || php.length > 0) {
    runNpm(['run', 'pint:check', ...(pintConfigChanged ? [] : ['--', ...php])], 'Checking PHP style with Pint');
}
if (lintConfigChanged || lint.length > 0) {
    runNpm(
        ['run', lintConfigChanged ? 'lint:check' : 'lint:staged', ...(lintConfigChanged ? [] : ['--', ...lint])],
        'Checking JavaScript and TypeScript with Oxlint',
    );
}
if (formatConfigChanged || format.length > 0) {
    runNpm(
        ['run', formatConfigChanged ? 'format:check' : 'format:staged', ...(formatConfigChanged ? [] : ['--', ...format])],
        'Checking frontend formatting with Oxfmt',
    );
}
if (openapiChanged) {
    runNpm(['run', 'openapi:check'], 'Checking OpenAPI specification');
}

console.log('[pre-commit] All applicable checks passed.');
