import { describe, expect, it } from 'vitest';

import {
    extractRorId,
    getInstitutionKey,
    humanizeContributorType,
    resolveRorUrl,
} from '@/pages/LandingPages/components/relation-browser/use-institution-nodes';

describe('use-institution-nodes utilities', () => {
    describe('resolveRorUrl', () => {
        it('returns null for null or undefined input', () => {
            expect(resolveRorUrl(null)).toBeNull();
            expect(resolveRorUrl(undefined)).toBeNull();
        });

        it('returns null for empty string', () => {
            expect(resolveRorUrl('')).toBeNull();
        });

        it('returns the URL unchanged if it already starts with https://ror.org/', () => {
            expect(resolveRorUrl('https://ror.org/04z8jg394')).toBe('https://ror.org/04z8jg394');
        });

        it('converts a bare ROR ID into a full URL', () => {
            expect(resolveRorUrl('04z8jg394')).toBe('https://ror.org/04z8jg394');
        });

        it('returns null for non-ROR identifiers', () => {
            expect(resolveRorUrl('https://orcid.org/0000-0001-2345-6789')).toBeNull();
            expect(resolveRorUrl('random-string')).toBeNull();
        });
    });

    describe('extractRorId', () => {
        it('returns null for null or undefined input', () => {
            expect(extractRorId(null)).toBeNull();
            expect(extractRorId(undefined)).toBeNull();
        });

        it('returns null for empty string', () => {
            expect(extractRorId('')).toBeNull();
        });

        it('extracts ROR ID from a full URL', () => {
            expect(extractRorId('https://ror.org/04z8jg394')).toBe('04z8jg394');
        });

        it('extracts bare ROR ID', () => {
            expect(extractRorId('04z8jg394')).toBe('04z8jg394');
        });

        it('returns null for non-ROR identifiers', () => {
            expect(extractRorId('0000-0001-2345-6789')).toBeNull();
            expect(extractRorId('not-a-ror')).toBeNull();
        });
    });

    describe('getInstitutionKey', () => {
        it('returns ROR-based key when rorId is provided', () => {
            expect(getInstitutionKey('GFZ Helmholtz Centre', '04z8jg394')).toBe('ror-04z8jg394');
        });

        it('returns name-based key when rorId is null', () => {
            expect(getInstitutionKey('GFZ Helmholtz Centre', null)).toBe('name-gfz helmholtz centre');
        });

        it('normalizes name to lowercase and trims whitespace', () => {
            expect(getInstitutionKey('  GFZ Helmholtz Centre  ', null)).toBe('name-gfz helmholtz centre');
        });

        it('prioritizes ROR over name', () => {
            const key = getInstitutionKey('GFZ', '04z8jg394');
            expect(key).toMatch(/^ror-/);
        });
    });

    describe('humanizeContributorType', () => {
        it('converts PascalCase to space-separated words', () => {
            expect(humanizeContributorType('DataCollector')).toBe('Data Collector');
        });

        it('handles single word', () => {
            expect(humanizeContributorType('Editor')).toBe('Editor');
        });

        it('handles multi-word types', () => {
            expect(humanizeContributorType('HostingInstitution')).toBe('Hosting Institution');
            expect(humanizeContributorType('ContactPerson')).toBe('Contact Person');
        });

        it('handles ResearchGroup', () => {
            expect(humanizeContributorType('ResearchGroup')).toBe('Research Group');
        });
    });
});
