import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// npm 12.2.1 bundles a vulnerable node-gyp dependency. Remove this workaround
// after a stable npm release includes the fix: https://github.com/npm/cli/issues/10163
const version = '6.29.1';
const integrity = 'EvRlUt/QqAEvXdQeRMYUfwOQsfzpDztykrG2owGEE/rBOBoYv9uF39ZzsNDh4lTFHhTr2XOZjKkYgueD12ZmGA==';
const readPackage = (directory) => JSON.parse(readFileSync(join(directory, 'package.json'), 'utf8'));

async function patch() {
    if (!process.argv[2]) throw new Error('Usage: node scripts/patch-npm-undici.mjs <npm package directory>');
    const npmDirectory = realpathSync(process.argv[2]);
    const npm = readPackage(npmDirectory);
    if (npm.name !== 'npm' || npm.version !== '12.2.1') throw new Error('Expected npm 12.2.1; review this temporary security patch before changing npm.');
    const target = join(npmDirectory, 'node_modules', 'undici');
    if (realpathSync(target) !== target) throw new Error('Refusing to replace a linked npm dependency.');
    const current = readPackage(target);
    if (current.name !== 'undici' || !['6.29.0', version].includes(current.version)) throw new Error('Unexpected bundled Undici version.');
    if (current.version === version) { console.log(`[npm-security] Undici ${version} already installed.`); return; }
    const response = await fetch(`https://registry.npmjs.org/undici/-/undici-${version}.tgz`, { signal: AbortSignal.timeout(60_000) });
    if (!response.ok) throw new Error(`Undici download failed: HTTP ${response.status}.`);
    const archive = Buffer.from(await response.arrayBuffer());
    if (createHash('sha512').update(archive).digest('base64') !== integrity) throw new Error('Undici archive checksum mismatch.');
    const temporary = mkdtempSync(join(tmpdir(), 'ernie-npm-security-'));
    try {
        const file = join(temporary, 'undici.tgz');
        writeFileSync(file, archive);
        const extracted = spawnSync('tar', ['-xzf', file, '-C', temporary], { stdio: 'inherit' });
        if (extracted.error || extracted.status !== 0) throw new Error('Could not extract the verified Undici package.');
        const source = join(temporary, 'package');
        const replacement = readPackage(source);
        if (replacement.name !== 'undici' || replacement.version !== version || Object.keys(replacement.dependencies ?? {}).length) {
            throw new Error('Unexpected Undici package contents.');
        }
        // The resolved target was checked above and is strictly inside the supplied npm installation.
        rmSync(target, { recursive: true });
        cpSync(source, target, { recursive: true });
        console.log(`[npm-security] Updated npm's bundled Undici ${current.version} to ${version}.`);
    } finally { rmSync(temporary, { recursive: true, force: true }); }
}

try { await patch(); } catch (error) { console.error(error.message); process.exitCode = 1; }
