import { describe, expect, it } from 'vitest';
import { latestVersion } from '../version';
import changelog from '../../../data/changelog.json';

describe('latestVersion', () => {
    it('matches the first entry in the changelog', () => {
        expect(latestVersion).toBe(changelog[0].version);
    });
});
