import { describe, expect, it } from 'vitest';

import { getValidRorUrl, hasMslLaboratoryTrigger, toMslLaboratorySelection } from '@/components/curation/utils/msl-laboratories';

describe('hasMslLaboratoryTrigger', () => {
    it.each([['EPOS'], [' epos '], ['Multi-Scale Laboratories'], ['  MULTI-SCALE LABORATORIES  ']])(
        'accepts the exact, normalized free keyword %s',
        (keyword) => {
            expect(hasMslLaboratoryTrigger([keyword])).toBe(true);
        },
    );

    it.each([['depositional'], ['MSL'], ['Multi Scale Laboratories'], ['EPOS dataset'], ['my multi-scale laboratories project']])(
        'does not accept the free-keyword alias or substring %s',
        (keyword) => {
            expect(hasMslLaboratoryTrigger([keyword])).toBe(false);
        },
    );

    it('accepts a controlled MSL keyword independently of free keywords', () => {
        expect(hasMslLaboratoryTrigger([], true)).toBe(true);
    });
});

describe('MSL laboratory helpers', () => {
    const vocabularyEntry = {
        identifier: 'lab:one',
        name: 'Rock Lab',
        display_name: 'Rock Lab (Example University)',
        affiliation_name: 'Example University',
        affiliation_ror: 'https://ror.org/04pp8hn57',
        scientific_domain: 'Rock physics',
        country: 'Netherlands',
    };

    it('serializes only the four resource fields', () => {
        expect(toMslLaboratorySelection(vocabularyEntry)).toEqual({
            identifier: 'lab:one',
            name: 'Rock Lab',
            affiliation_name: 'Example University',
            affiliation_ror: 'https://ror.org/04pp8hn57',
        });
    });

    it('does not copy a non-canonical ROR value into a new selection', () => {
        expect(toMslLaboratorySelection({ ...vocabularyEntry, affiliation_ror: 'javascript:alert(1)' }).affiliation_ror).toBeNull();
    });

    it('only accepts canonical ROR URLs', () => {
        expect(getValidRorUrl('https://ror.org/04pp8hn57/')).toBe('https://ror.org/04pp8hn57');
        expect(getValidRorUrl('javascript:alert(1)')).toBeNull();
        expect(getValidRorUrl('https://example.test/04pp8hn57')).toBeNull();
        expect(getValidRorUrl(null)).toBeNull();
    });
});
