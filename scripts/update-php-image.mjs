import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const stableVersion = /^8\.5\.(0|[1-9]\d*)$/u;
const phpFrom = /^FROM (php:(8\.5\.\d+)-fpm-trixie@sha256:[a-f0-9]{64})(?= AS )/gmu;

export function preparePhpImageUpdate({ version, release, manifest, files }) {
    if (!stableVersion.test(version)) {
        throw new Error('Choose an exact stable PHP 8.5 patch version, for example 8.5.11.');
    }
    if (release.version !== version || !release.date || !release.source?.some(
        (source) => source.filename === `php-${version}.tar.xz` && /^[a-f0-9]{64}$/u.test(source.sha256),
    )) {
        throw new Error(`PHP ${version} is not confirmed by the official release metadata. No files changed.`);
    }
    if (!/^sha256:[a-f0-9]{64}$/u.test(manifest.digest)) {
        throw new Error('The registry did not return a valid image digest. No files changed.');
    }
    for (const architecture of ['amd64', 'arm64']) {
        if (!manifest.manifests?.some((entry) => entry.platform?.os === 'linux'
            && entry.platform.architecture === architecture)) {
            throw new Error(`The PHP image is not yet available for linux/${architecture}. No files changed.`);
        }
    }
    const image = `php:${version}-fpm-trixie@${manifest.digest}`;
    const changes = new Map();
    const currentImages = new Set();
    for (const [filename, content] of files) {
        const matches = [...content.matchAll(phpFrom)];
        if (matches.length !== 1) {
            throw new Error(`Expected exactly one pinned PHP 8.5 FROM in ${filename}. No files changed.`);
        }
        const [, currentImage, currentVersion] = matches[0];
        currentImages.add(currentImage);
        if (Number(version.split('.')[2]) < Number(currentVersion.split('.')[2])) {
            throw new Error(`Refusing to downgrade ${filename} from PHP ${currentVersion}. No files changed.`);
        }
        changes.set(filename, content.replace(phpFrom, `FROM ${image}`));
    }
    if (currentImages.size !== 1 || files.size !== 2) {
        throw new Error('Both Dockerfiles must start from the same pinned PHP image. No files changed.');
    }
    return { image, changes };
}

async function main() {
    const args = process.argv.slice(2);
    const version = args[0];
    const dryRun = args[1] === '--dry-run';
    if (!stableVersion.test(version ?? '') || args.length > 2 || (args.length === 2 && !dryRun)) {
        throw new Error('Usage: npm run update:php-image -- 8.5.PATCH [--dry-run]');
    }
    const root = fileURLToPath(new URL('../', import.meta.url));
    const files = new Map(['Dockerfile', 'Dockerfile.dev'].map((name) => {
        const filename = resolve(root, name);
        return [filename, readFileSync(filename, 'utf8')];
    }));
    const url = `https://www.php.net/releases/index.php?json&version=${version}`;
    const response = await fetch(url, { signal: AbortSignal.timeout(30_000) });
    if (!response.ok) {
        throw new Error(`Official PHP release lookup failed: HTTP ${response.status}. No files changed.`);
    }
    const release = await response.json();
    const inspection = spawnSync('docker', [
        'buildx', 'imagetools', 'inspect', `php:${version}-fpm-trixie`, '--format', '{{json .Manifest}}',
    ], { encoding: 'utf8', timeout: 120_000, maxBuffer: 4 * 1024 * 1024 });
    if (inspection.error || inspection.status !== 0) {
        throw new Error(`The official Docker image is not available: ${inspection.error?.message ?? inspection.stderr.trim()}. No files changed.`);
    }
    const { image, changes } = preparePhpImageUpdate({ version, release, manifest: JSON.parse(inspection.stdout), files });
    if (!dryRun) {
        for (const [filename, content] of changes) {
            writeFileSync(filename, content);
        }
    }
    console.log(`${dryRun ? 'Verified' : 'Updated both Dockerfiles to'} ${image}`);
    console.log('Rebuild the images, run backend and MySQL checks, and follow the documented Stage/Production release process.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        console.error(`[php-image] ${error.message}`);
        process.exitCode = 1;
    });
}
