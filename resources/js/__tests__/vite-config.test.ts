import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const currentDir = dirname(fileURLToPath(import.meta.url));
const configPath = resolve(currentDir, '../../..', 'vite.config.ts');
const viteConfigSource = readFileSync(configPath, 'utf8');

describe('vite configuration', () => {
    it('avoids hardcoding the /ernie base path', () => {
        expect(viteConfigSource).not.toMatch(/base\s*:\s*['\"]\/ernie\//);
    });
});
