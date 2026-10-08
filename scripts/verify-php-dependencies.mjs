import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export function verifyPhpDependencies(lock, installed) {
    const packages = new Map(installed.packages.map((item) => [item.name, item]));
    const expected = [...lock.packages, ...(lock['packages-dev'] ?? [])];
    const mismatches = expected.filter((item) => {
        const actual = packages.get(item.name);
        const reference = (entry) => entry?.dist?.reference ?? entry?.source?.reference;
        return actual?.version !== item.version || reference(actual) !== reference(item);
    }).map((item) => item.name);
    const names = new Set(expected.map((item) => item.name));
    mismatches.push(...packages.keys().filter((name) => !names.has(name)));
    if (mismatches.length) {
        throw new Error(`Docker PHP dependencies differ from composer.lock: ${mismatches.join(', ')}. Run npm run composer:app -- install.`);
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const json = (path) => JSON.parse(readFileSync(path, 'utf8'));
        verifyPhpDependencies(json('composer.lock'), json('vendor/composer/installed.json'));
    } catch (error) {
        console.error(`[tests] ${error.message}`);
        process.exitCode = 1;
    }
}
