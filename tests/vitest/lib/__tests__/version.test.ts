import changelog from '@data/changelog.json';
import { describe, expect, it } from 'vitest';

import { latestVersion } from '@/lib/version';

describe('latestVersion', () => {
    it('matches the first entry in the changelog', () => {
        expect(latestVersion).toBe(changelog[0].version);
    });
});
