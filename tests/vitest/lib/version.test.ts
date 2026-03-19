import { describe, expect, it } from 'vitest';

import { latestVersion } from '@/lib/version';

describe('version', () => {
    it('exports a version string', () => {
        expect(typeof latestVersion).toBe('string');
    });

    it('follows semver format', () => {
        expect(latestVersion).toMatch(/^\d+\.\d+\.\d+(-?[\da-zA-Z-]+(\.[\da-zA-Z-]+)*)?(\+[\da-zA-Z-]+(\.[\da-zA-Z-]+)*)?$/);
    });
});
