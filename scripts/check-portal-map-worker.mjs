import { readFileSync, statSync } from 'node:fs';
import { join, resolve, sep } from 'node:path';

const buildDirectory = resolve(process.argv[2] ?? 'public/build');
const manifest = JSON.parse(readFileSync(join(buildDirectory, 'manifest.json'), 'utf8'));
const source = 'node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs';
const workers = Object.values(manifest).filter((entry) => {
    const path = entry.src?.split('?')[0];
    return entry.isWorkerEntry === true && (path === source || path?.endsWith(`/${source}`));
});

if (workers.length === 0) {
    throw new Error(`Vite manifest has no worker entry for ${source}`);
}
for (const worker of workers) {
    const file = resolve(buildDirectory, worker.file);
    if (!file.startsWith(buildDirectory + sep) || !statSync(file, { throwIfNoEntry: false })?.isFile()) {
        throw new Error(`MapLibre worker file missing from the build: ${worker.file}`);
    }
    console.log(`Verified MapLibre worker: ${worker.file}`);
}
