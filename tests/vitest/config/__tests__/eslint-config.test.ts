import { describe, expect, it } from 'vitest';

import eslintConfig from '../../../../eslint.config.js';

describe('eslint import sorting configuration', () => {
    it('enforces simple-import-sort for imports and exports', () => {
        const pluginConfig = (eslintConfig as Array<Record<string, unknown>>).find(
            (config) =>
                typeof config === 'object' &&
                config !== null &&
                'plugins' in config &&
                (config.plugins as Record<string, unknown> | undefined)?.['simple-import-sort']
        );

        expect(pluginConfig).toBeDefined();

        const rules = (pluginConfig?.rules ?? {}) as Record<string, unknown>;

        expect(rules['simple-import-sort/imports']).toBe('error');
        expect(rules['simple-import-sort/exports']).toBe('error');
    });
});
