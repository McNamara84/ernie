import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { resolveLocalVitestMaxWorkers } from '../../../vite.config';

const currentDir = dirname(fileURLToPath(import.meta.url));
const configPath = resolve(currentDir, '../../..', 'vite.config.ts');
const viteConfigSource = readFileSync(configPath, 'utf8');

describe('vite configuration', () => {
    it('avoids hardcoding the /ernie base path', () => {
        expect(viteConfigSource).not.toMatch(/base\s*:\s*['"]\/ernie\//);
    });

    it.each([
        [1, 1],
        [2, 1],
        [4, 2],
        [16, 8],
        [32, 8],
    ])('uses %i available CPUs to select %i local workers', (availableCpus, expectedWorkers) => {
        expect(resolveLocalVitestMaxWorkers(undefined, availableCpus)).toBe(expectedWorkers);
    });

    it('accepts an explicit positive worker override', () => {
        expect(resolveLocalVitestMaxWorkers('3', 1)).toBe(3);
    });

    it.each(['0', '-1', '1.5', 'invalid'])('rejects the invalid worker override %s', (configuredWorkers) => {
        expect(() => resolveLocalVitestMaxWorkers(configuredWorkers, 16)).toThrow(
            'ERNIE_VITEST_WORKERS must be a positive integer.',
        );
    });
});
