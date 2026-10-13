import { globSync, readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { testIgnorePatterns, testMatchPatterns } from '../tests/playwright/playwright.shared.ts';

const root = fileURLToPath(new URL('../', import.meta.url));
const files = (patterns, exclude = []) => globSync(patterns, { cwd: root, exclude })
    .map((path) => path.replaceAll('\\', '/')).sort();

export function testInventory() {
    const phpunit = readFileSync(new URL('../phpunit.xml', import.meta.url), 'utf8');
    const suites = phpunit.match(/<testsuites>([\s\S]*?)<\/testsuites>/)?.[1];
    if (!suites) {
        throw new Error('No PHPUnit testsuites configured.');
    }
    const pestDirectories = [...suites.matchAll(/<directory>([^<]+)<\/directory>/g)].map((match) => match[1]);
    const pest = files(pestDirectories.map((directory) => `${directory}/**/*Test.php`));
    const allPest = files('tests/pest/**/*Test.php');
    const allPlaywright = files('tests/playwright/**/*.spec.ts');
    const playwright = files(testMatchPatterns, testIgnorePatterns);
    const pureUnit = JSON.parse(readFileSync(new URL('../tests/pest/pure-unit-tests.json', import.meta.url), 'utf8'))
        .map((path) => `tests/pest/${path}`);
    if (new Set(pureUnit).size !== pureUnit.length || pureUnit.some((path) => !pest.includes(path))) {
        throw new Error('Pure unit manifest contains duplicate or undiscovered files.');
    }

    return {
        schemaVersion: 1,
        kind: 'configured-file-inventory',
        note: 'File discovery only; runtime dataset counts and conditional skips come from actual suite reports.',
        pest: { configured: pest, pureUnit, outsideDefaultSuites: allPest.filter((path) => !pest.includes(path)) },
        vitest: { configured: files('tests/vitest/**/*.{test,spec}.{js,ts,jsx,tsx}') },
        playwright: { configured: playwright, outsideDefaultSuites: allPlaywright.filter((path) => !playwright.includes(path)) },
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const inventory = testInventory();
    console.log(JSON.stringify(process.argv.includes('--json') ? inventory : {
        kind: inventory.kind,
        note: inventory.note,
        pest: inventory.pest.configured.length,
        pureUnit: inventory.pest.pureUnit.length,
        pestOutsideDefault: inventory.pest.outsideDefaultSuites,
        vitest: inventory.vitest.configured.length,
        playwright: inventory.playwright.configured.length,
        playwrightOutsideDefault: inventory.playwright.outsideDefaultSuites,
    }, null, 2));
}
