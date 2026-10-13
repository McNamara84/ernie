import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

// Vite uses a Docker-managed node_modules volume, separate from host-side
// Vitest. An old volume can serve incompatible modules despite a clean host.
const expectedNode = readFileSync(new URL('../.node-version', import.meta.url), 'utf8').trim();
if (process.versions.node !== expectedNode) {
    console.error(`[playwright] Vite requires Node ${expectedNode}; received ${process.versions.node}.`);
    process.exit(1);
}
const result = spawnSync('npm', ['ls', '--depth=0', '--json'], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
if (result.error || result.signal || result.status !== 0) {
    console.error('[playwright] Vite dependencies are invalid. Restore the Docker node_modules volume with npm ci; see docs/testing.md.');
    process.exit(1);
}
