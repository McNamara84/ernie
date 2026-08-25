import { describe, expect, it } from 'vitest';

import {
    getSchemeFromVocabularyType,
    getVocabularyTypeFromScheme,
} from '@/types/vocabulary';

describe('getVocabularyTypeFromScheme', () => {
    it('returns "science" for Science Keywords scheme', () => {
        expect(getVocabularyTypeFromScheme('Science Keywords')).toBe('science');
    });

    it('returns "platforms" for Platforms scheme', () => {
        expect(getVocabularyTypeFromScheme('Platforms')).toBe('platforms');
    });

    it('returns "instruments" for Instruments scheme', () => {
        expect(getVocabularyTypeFromScheme('Instruments')).toBe('instruments');
    });

    it('returns "msl" for EPOS MSL vocabulary scheme', () => {
        expect(getVocabularyTypeFromScheme('EPOS MSL vocabulary')).toBe('msl');
    });

    it('returns "chronostratigraphy" for International Chronostratigraphic Chart scheme', () => {
        expect(getVocabularyTypeFromScheme('International Chronostratigraphic Chart')).toBe('chronostratigraphy');
    });

    it('returns "gemet" for GEMET scheme', () => {
        expect(getVocabularyTypeFromScheme('GEMET - GEneral Multilingual Environmental Thesaurus')).toBe('gemet');
    });

    it('returns "analytical_methods" for Analytical Methods scheme', () => {
        expect(getVocabularyTypeFromScheme('Analytical Methods for Geochemistry and Cosmochemistry')).toBe('analytical_methods');
    });

    it('returns "simple_lithology" for the canonical and legacy CGI scheme names', () => {
        expect(getVocabularyTypeFromScheme('CGI Simple Lithology')).toBe('simple_lithology');
        expect(getVocabularyTypeFromScheme('CGI Simple Lithology Vocabulary')).toBe('simple_lithology');
    });

    it('is case-insensitive', () => {
        expect(getVocabularyTypeFromScheme('SCIENCE KEYWORDS')).toBe('science');
        expect(getVocabularyTypeFromScheme('PLATFORMS')).toBe('platforms');
        expect(getVocabularyTypeFromScheme('INSTRUMENTS')).toBe('instruments');
        expect(getVocabularyTypeFromScheme('epos msl')).toBe('msl');
        expect(getVocabularyTypeFromScheme('INTERNATIONAL CHRONOSTRATIGRAPHIC CHART')).toBe('chronostratigraphy');
        expect(getVocabularyTypeFromScheme('GEMET - GENERAL MULTILINGUAL ENVIRONMENTAL THESAURUS')).toBe('gemet');
        expect(getVocabularyTypeFromScheme('ANALYTICAL METHODS FOR GEOCHEMISTRY')).toBe('analytical_methods');
        expect(getVocabularyTypeFromScheme('CGI SIMPLE LITHOLOGY')).toBe('simple_lithology');
    });

    it('handles partial matches', () => {
        expect(getVocabularyTypeFromScheme('NASA Science Keywords v8')).toBe('science');
        expect(getVocabularyTypeFromScheme('Observation Platforms')).toBe('platforms');
        expect(getVocabularyTypeFromScheme('Scientific Instruments List')).toBe('instruments');
        expect(getVocabularyTypeFromScheme('MSL Vocabularies')).toBe('msl');
        expect(getVocabularyTypeFromScheme('ICS Chronostratigraphic Chart 2020')).toBe('chronostratigraphy');
        expect(getVocabularyTypeFromScheme('GEMET Thesaurus')).toBe('gemet');
        expect(getVocabularyTypeFromScheme('Geochem Methods v2')).toBe('analytical_methods');
    });

    it('returns "science" as default for unknown schemes', () => {
        expect(getVocabularyTypeFromScheme('Unknown')).toBe('science');
        expect(getVocabularyTypeFromScheme('')).toBe('science');
        expect(getVocabularyTypeFromScheme('Custom Vocabulary')).toBe('science');
    });
});

describe('getSchemeFromVocabularyType', () => {
    it('returns "Science Keywords" for science type', () => {
        expect(getSchemeFromVocabularyType('science')).toBe('Science Keywords');
    });

    it('returns "Platforms" for platforms type', () => {
        expect(getSchemeFromVocabularyType('platforms')).toBe('Platforms');
    });

    it('returns "Instruments" for instruments type', () => {
        expect(getSchemeFromVocabularyType('instruments')).toBe('Instruments');
    });

    it('returns "EPOS MSL vocabulary" for msl type', () => {
        expect(getSchemeFromVocabularyType('msl')).toBe('EPOS MSL vocabulary');
    });

    it('returns "International Chronostratigraphic Chart" for chronostratigraphy type', () => {
        expect(getSchemeFromVocabularyType('chronostratigraphy')).toBe('International Chronostratigraphic Chart');
    });

    it('returns "GEMET - GEneral Multilingual Environmental Thesaurus" for gemet type', () => {
        expect(getSchemeFromVocabularyType('gemet')).toBe('GEMET - GEneral Multilingual Environmental Thesaurus');
    });

    it('returns "Analytical Methods for Geochemistry and Cosmochemistry" for analytical_methods type', () => {
        expect(getSchemeFromVocabularyType('analytical_methods')).toBe('Analytical Methods for Geochemistry and Cosmochemistry');
    });

    it('returns "CGI Simple Lithology" for simple_lithology type', () => {
        expect(getSchemeFromVocabularyType('simple_lithology')).toBe('CGI Simple Lithology');
    });

    it('returns "Science Keywords" as default for unknown types', () => {
        // TypeScript would normally prevent this, but testing runtime behavior
        expect(getSchemeFromVocabularyType('unknown' as 'science')).toBe('Science Keywords');
    });
});
