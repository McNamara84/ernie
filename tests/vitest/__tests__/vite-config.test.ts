import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { parseAst } from 'vite';
import { describe, expect, it } from 'vitest';

import { ignoreGeneratedCoverage, resolveVitestMaxWorkers, resolveVitestRetry } from '../../../vitest.shared';

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
        expect(resolveVitestMaxWorkers(false, '', availableCpus)).toBe(expectedWorkers);
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

    it('retries a failed test once in CI', () => {
        expect(resolveVitestRetry(true)).toBe(1);
    });

    it('keeps local test runs strict', () => {
        expect(resolveVitestRetry(false)).toBe(0);
    });

    it.each([
        ['var _jsxFileName = "D:/project/component.tsx"', true],
        ['var _jsxFileName = "/project/component.jsx"', true],
        ['const filename = "/project/component.tsx"', false],
        ['const _jsxFileName = getFilename()', false],
        ['const _jsxFileName = "application data"', false],
        ['let _jsxFileName', false],
        ['const _jsxDEV = __vite__cjsImport3_react_jsxDevRuntime["jsxDEV"]', true],
        ['const value = applicationData["field"]', false],
        ['const value = __vite__cjsImportUserData["field"]', false],
        ['const lib = __vite__cjsImport0_lib.__esModule ? __vite__cjsImport0_lib.default : __vite__cjsImport0_lib', true],
        ['const value = applicationData.__esModule ? applicationData.default : applicationData', false],
        ['const Papa = __vite__cjsImport1_papaparse', true],
        ['const value = applicationData', false],
    ])('keeps application coverage while checking generated code: %s', (source, ignored) => {
        const statement = parseAst(source).body[0];
        if (statement.type !== 'VariableDeclaration') throw new Error('Expected a variable declaration.');
        expect(ignoreGeneratedCoverage(statement.declarations[0], 'statement')).toBe(ignored);
        expect(ignoreGeneratedCoverage(statement.declarations[0], 'branch')).toBe(false);
        expect(ignoreGeneratedCoverage(statement.declarations[0], 'function')).toBe(false);
    });
});
