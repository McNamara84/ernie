import { createHash } from 'node:crypto';
import { globSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

/** Copy generated files only when their contents changed, preserving mtimes. */
export function syncWayfinderFiles(source, destination, previousFiles = {}) {
    const updated = [];
    function visit(relative = '') {
        for (const entry of readdirSync(join(source, relative), { withFileTypes: true })) {
            const path = join(relative, entry.name);
            if (entry.isDirectory()) {
                visit(path);
            } else if (entry.isFile()) {
                const contents = readFileSync(join(source, path));
                const target = join(destination, path);
                let previous;
                try {
                    previous = readFileSync(target);
                } catch (error) {
                    if (error.code !== 'ENOENT') throw error;
                }
                if (!previous?.equals(contents)) {
                    mkdirSync(dirname(target), { recursive: true });
                    writeFileSync(target, contents);
                    updated.push(path);
                }
            }
        }
    }
    visit();
    for (const [path, hash] of Object.entries(previousFiles)) {
        const target = resolve(destination, path);
        if (!target.startsWith(`${resolve(destination)}\\`) && !target.startsWith(`${resolve(destination)}/`)) {
            throw new Error('Generated file must remain inside its destination.');
        }
        try {
            readFileSync(join(source, path));
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
            try {
                if (createHash('sha256').update(readFileSync(target)).digest('hex') !== hash) {
                    throw new Error(`Obsolete generated file has local edits: ${path}`, { cause: error });
                }
                rmSync(target);
                updated.push(path);
            } catch (targetError) {
                if (targetError.code !== 'ENOENT') throw targetError;
            }
        }
    }
    return updated;
}

export function wayfinderFileHashes(directory) {
    return Object.fromEntries(
        globSync('**/*.ts', { cwd: directory })
            .sort()
            .map((path) => [
                path.replaceAll('\\', '/'),
                createHash('sha256')
                    .update(readFileSync(join(directory, path)))
                    .digest('hex'),
            ]),
    );
}

export function wayfinderInputFingerprint(command, root = process.cwd()) {
    const hash = createHash('sha256').update(command);
    for (const path of globSync(['app/**/*.php', 'routes/**/*.php', 'config/**/*.php', 'bootstrap/*.php', 'composer.lock'], { cwd: root }).sort()) {
        hash.update(path).update(readFileSync(join(root, path)));
    }
    for (const path of ['.env', '.env.docker']) {
        try {
            hash.update(String(statSync(join(root, path)).mtimeMs));
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
        }
    }
    return hash
        .update(process.env.APP_URL ?? '')
        .update(process.env.VITE_APP_URL ?? '')
        .digest('hex');
}

export function wayfinderCacheMatches(manifest, fingerprint, destination) {
    if (manifest?.fingerprint !== fingerprint || !Object.keys(manifest.files ?? {}).length) return false;
    return Object.entries(manifest.files).every(([path, hash]) => {
        try {
            return (
                createHash('sha256')
                    .update(readFileSync(join(destination, path)))
                    .digest('hex') === hash
            );
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
            return false;
        }
    });
}

/** Inputs not necessarily imported by a test must invalidate the local shortcut. */
export function vitestInputFingerprint(root = process.cwd()) {
    const hash = createHash('sha256').update(process.versions.node);
    for (const path of [
        'package-lock.json',
        'vite.config.ts',
        'vitest.config.ts',
        'vitest.shared.ts',
        'vitest.jsdom.config.ts',
        'vitest.browser.config.ts',
        'vitest.setup.ts',
        'tests/vitest-browser/setup.ts',
        'tests/vitest-browser/helpers/commands.ts',
        'scripts/run-vitest.mjs',
        'scripts/vitest-runtime.mjs',
    ]) {
        hash.update(path).update(readFileSync(resolve(root, path)));
    }
    return hash.digest('hex');
}

const recordingFlag = '--experimental.recordDependencies';
const recordingFlags = [recordingFlag, `${recordingFlag}=true`];
const recordsDependencies = (args) => recordingFlags.some((flag) => args.includes(flag));

// Only an explicitly recorded, unfiltered run can certify the local baseline.
const isCompleteRecordingRun = (args) =>
    args[0] === 'run' && recordsDependencies(args) && args.slice(1).every((arg) => ['--coverage', ...recordingFlags].includes(arg));

export function resolveAffectedArguments(args, fingerprint, previousFingerprint, isCi = Boolean(process.env.CI)) {
    if (!args.includes('--since-last-run')) return { args, fullRun: isCompleteRecordingRun(args) };
    if (isCi) throw new Error('--since-last-run is a local shortcut; CI must run the complete suite.');
    if (args.includes(`${recordingFlag}=false`)) throw new Error('--since-last-run requires dependency recording.');
    const recordingArgs = recordsDependencies(args) ? args : [...args, recordingFlag];
    if (fingerprint === previousFingerprint) return { args: recordingArgs, fullRun: false };
    const completeArgs = recordingArgs.filter((arg) => arg !== '--since-last-run');
    return {
        args: completeArgs,
        fullRun: isCompleteRecordingRun(completeArgs),
    };
}
