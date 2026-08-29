import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { resolveVitestMaxWorkers } from '../../../vite.config';

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
        expect(resolveVitestMaxWorkers(false, undefined, availableCpus)).toBe(expectedWorkers);
    });

    it('accepts an explicit positive worker override', () => {
        expect(resolveVitestMaxWorkers(false, '3', 1)).toBe(3);
    });

    it.each(['', ' ', '\t\r\n'])('treats the blank worker override %j as unset', (configuredWorkers) => {
        expect(resolveVitestMaxWorkers(false, configuredWorkers, 8)).toBe(4);
    });

    it.each(['0', '-1', '1.5', 'invalid'])('rejects the invalid worker override %s', (configuredWorkers) => {
        expect(() => resolveVitestMaxWorkers(false, configuredWorkers, 16)).toThrow('ERNIE_VITEST_WORKERS must be a positive integer.');
    });

    it.each([undefined, '', 'invalid', '0'])('ignores the worker override %j in CI', (configuredWorkers) => {
        expect(resolveVitestMaxWorkers(true, configuredWorkers)).toBeUndefined();
    });
});
