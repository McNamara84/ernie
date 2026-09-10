import { describe, expect, it } from 'vitest';

import oxfmtConfig from '../../../../.oxfmtrc.json';
import oxlintConfig from '../../../../.oxlintrc.json';

describe('Oxlint and Oxfmt configuration', () => {
    it('enforces import and export sorting through the Oxlint plugin bridge', () => {
        expect(oxlintConfig.jsPlugins).toContain('eslint-plugin-simple-import-sort');
        expect(oxlintConfig.rules['simple-import-sort/imports']).toBe('error');
        expect(oxlintConfig.rules['simple-import-sort/exports']).toBe('error');
    });

    it('preserves Tailwind CSS class sorting in Oxfmt', () => {
        expect(oxfmtConfig.sortTailwindcss.stylesheet).toBe('resources/css/app.css');
        expect(oxfmtConfig.sortTailwindcss.functions).toEqual(['clsx', 'cn']);
    });
});
