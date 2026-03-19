import { describe, expect, it } from 'vitest';

import {
    getSchemeLabel,
    SCHEME_GCMD_INSTRUMENTS,
    SCHEME_GCMD_PLATFORMS,
    SCHEME_GCMD_SCIENCE,
    SCHEME_GEMET,
    SCHEME_ICS_CHRONOSTRAT,
    SCHEME_LABELS,
    SCHEME_MSL,
} from '@/lib/keyword-schemes';

describe('keyword-schemes constants', () => {
    it('exports correct scheme identifiers', () => {
        expect(SCHEME_GCMD_SCIENCE).toBe('Science Keywords');
        expect(SCHEME_GCMD_PLATFORMS).toBe('Platforms');
        expect(SCHEME_GCMD_INSTRUMENTS).toBe('Instruments');
        expect(SCHEME_MSL).toBe('EPOS MSL vocabulary');
        expect(SCHEME_GEMET).toBe('GEMET - GEneral Multilingual Environmental Thesaurus');
        expect(SCHEME_ICS_CHRONOSTRAT).toBe('International Chronostratigraphic Chart');
    });

    it('exports SCHEME_LABELS with all expected entries', () => {
        expect(SCHEME_LABELS['']).toBe('Free Keywords');
        expect(SCHEME_LABELS[SCHEME_GCMD_SCIENCE]).toBe('GCMD Science Keywords');
        expect(SCHEME_LABELS[SCHEME_GCMD_PLATFORMS]).toBe('GCMD Platforms');
        expect(SCHEME_LABELS[SCHEME_GCMD_INSTRUMENTS]).toBe('GCMD Instruments');
        expect(SCHEME_LABELS[SCHEME_MSL]).toBe('MSL Vocabularies');
        expect(SCHEME_LABELS[SCHEME_GEMET]).toBe('GEMET Thesaurus');
        expect(SCHEME_LABELS[SCHEME_ICS_CHRONOSTRAT]).toBe('ICS Chronostratigraphy');
    });
});

describe('getSchemeLabel', () => {
    it('returns label for known scheme', () => {
        expect(getSchemeLabel('Science Keywords')).toBe('GCMD Science Keywords');
        expect(getSchemeLabel('Platforms')).toBe('GCMD Platforms');
        expect(getSchemeLabel('Instruments')).toBe('GCMD Instruments');
    });

    it('returns "Free Keywords" for empty string', () => {
        expect(getSchemeLabel('')).toBe('Free Keywords');
    });

    it('returns "Free Keywords" for null', () => {
        expect(getSchemeLabel(null)).toBe('Free Keywords');
    });

    it('returns the scheme string itself for unknown schemes', () => {
        expect(getSchemeLabel('Unknown Scheme')).toBe('Unknown Scheme');
    });
});
