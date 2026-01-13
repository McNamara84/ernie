import { Archive, Code, Database, HelpCircle, Video } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { getResourceTypeIcon } from '@/pages/LandingPages/components/ResourceTypeIcons';

describe('getResourceTypeIcon', () => {
    it('returns Database icon for Dataset', () => {
        expect(getResourceTypeIcon('Dataset')).toBe(Database);
    });

    it('returns Code icon for Software', () => {
        expect(getResourceTypeIcon('Software')).toBe(Code);
    });

    it('returns Video icon for Audiovisual', () => {
        expect(getResourceTypeIcon('Audiovisual')).toBe(Video);
    });

    it('returns Archive icon for Collection', () => {
        expect(getResourceTypeIcon('Collection')).toBe(Archive);
    });

    it('returns HelpCircle for unknown resource type', () => {
        expect(getResourceTypeIcon('UnknownType')).toBe(HelpCircle);
    });

    it('returns HelpCircle for empty string', () => {
        expect(getResourceTypeIcon('')).toBe(HelpCircle);
    });

    it('is case-sensitive (returns HelpCircle for lowercase)', () => {
        // The function is case-sensitive
        expect(getResourceTypeIcon('dataset')).toBe(HelpCircle);
    });
});
