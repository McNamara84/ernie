import { describe, expect, it } from 'vitest';

import {
    affiliationTagSchema,
    doiSchema,
    isoDateSchema,
    latitudeSchema,
    longitudeSchema,
    optionalUrlSchema,
    orcidSchema,
    rorIdSchema,
    timeSchema,
    versionSchema,
    yearSchema,
} from '@/schemas/common.schema';

describe('Common Schemas', () => {
    describe('affiliationTagSchema', () => {
        it('accepts valid affiliation with ROR ID', () => {
            const result = affiliationTagSchema.safeParse({ value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394' });
            expect(result.success).toBe(true);
        });

        it('accepts affiliation without ROR ID', () => {
            const result = affiliationTagSchema.safeParse({ value: 'Some Lab', rorId: null });
            expect(result.success).toBe(true);
        });

        it('rejects empty affiliation name', () => {
            const result = affiliationTagSchema.safeParse({ value: '', rorId: null });
            expect(result.success).toBe(false);
        });
    });

    describe('isoDateSchema', () => {
        it('accepts valid ISO date', () => {
            expect(isoDateSchema.safeParse('2024-01-15').success).toBe(true);
        });

        it('rejects invalid date format', () => {
            expect(isoDateSchema.safeParse('01/15/2024').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(isoDateSchema.safeParse('').success).toBe(true);
        });

        it('accepts undefined', () => {
            expect(isoDateSchema.safeParse(undefined).success).toBe(true);
        });

        it('accepts null', () => {
            expect(isoDateSchema.safeParse(null).success).toBe(true);
        });
    });

    describe('timeSchema', () => {
        it('accepts valid time', () => {
            expect(timeSchema.safeParse('14:30').success).toBe(true);
        });

        it('rejects invalid time', () => {
            expect(timeSchema.safeParse('2:30 PM').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(timeSchema.safeParse('').success).toBe(true);
        });
    });

    describe('doiSchema', () => {
        it('accepts valid DOI', () => {
            expect(doiSchema.safeParse('10.5880/test.2024.001').success).toBe(true);
        });

        it('rejects invalid DOI', () => {
            expect(doiSchema.safeParse('doi:10.5880/test').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(doiSchema.safeParse('').success).toBe(true);
        });
    });

    describe('orcidSchema', () => {
        it('accepts valid ORCID', () => {
            expect(orcidSchema.safeParse('0000-0001-2345-6789').success).toBe(true);
        });

        it('accepts ORCID with X checksum', () => {
            expect(orcidSchema.safeParse('0000-0001-2345-678X').success).toBe(true);
        });

        it('rejects invalid ORCID format', () => {
            expect(orcidSchema.safeParse('0000-0001-2345').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(orcidSchema.safeParse('').success).toBe(true);
        });
    });

    describe('rorIdSchema', () => {
        it('accepts valid ROR ID', () => {
            expect(rorIdSchema.safeParse('https://ror.org/04z8jg394').success).toBe(true);
        });

        it('rejects invalid ROR ID', () => {
            expect(rorIdSchema.safeParse('ror.org/04z8jg394').success).toBe(false);
        });

        it('accepts null', () => {
            expect(rorIdSchema.safeParse(null).success).toBe(true);
        });

        it('accepts empty string', () => {
            expect(rorIdSchema.safeParse('').success).toBe(true);
        });
    });

    describe('optionalUrlSchema', () => {
        it('accepts valid URL', () => {
            expect(optionalUrlSchema.safeParse('https://example.com').success).toBe(true);
        });

        it('rejects invalid URL', () => {
            expect(optionalUrlSchema.safeParse('not-a-url').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(optionalUrlSchema.safeParse('').success).toBe(true);
        });
    });

    describe('yearSchema', () => {
        it('accepts valid year', () => {
            expect(yearSchema.safeParse('2024').success).toBe(true);
        });

        it('rejects year before 1900', () => {
            expect(yearSchema.safeParse('1800').success).toBe(false);
        });

        it('rejects non-4-digit string', () => {
            expect(yearSchema.safeParse('24').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(yearSchema.safeParse('').success).toBe(true);
        });
    });

    describe('versionSchema', () => {
        it('accepts semantic version', () => {
            expect(versionSchema.safeParse('1.0.0').success).toBe(true);
        });

        it('accepts simple version', () => {
            expect(versionSchema.safeParse('1').success).toBe(true);
        });

        it('rejects version with text', () => {
            expect(versionSchema.safeParse('v1.0.0').success).toBe(false);
        });

        it('accepts empty string', () => {
            expect(versionSchema.safeParse('').success).toBe(true);
        });
    });

    describe('latitudeSchema', () => {
        it('accepts valid latitude', () => {
            expect(latitudeSchema.safeParse('52.3906').success).toBe(true);
        });

        it('rejects latitude > 90', () => {
            expect(latitudeSchema.safeParse('91').success).toBe(false);
        });

        it('rejects latitude < -90', () => {
            expect(latitudeSchema.safeParse('-91').success).toBe(false);
        });

        it('accepts boundary values', () => {
            expect(latitudeSchema.safeParse('90').success).toBe(true);
            expect(latitudeSchema.safeParse('-90').success).toBe(true);
        });

        it('accepts empty string', () => {
            expect(latitudeSchema.safeParse('').success).toBe(true);
        });
    });

    describe('longitudeSchema', () => {
        it('accepts valid longitude', () => {
            expect(longitudeSchema.safeParse('13.0644').success).toBe(true);
        });

        it('rejects longitude > 180', () => {
            expect(longitudeSchema.safeParse('181').success).toBe(false);
        });

        it('rejects longitude < -180', () => {
            expect(longitudeSchema.safeParse('-181').success).toBe(false);
        });

        it('accepts boundary values', () => {
            expect(longitudeSchema.safeParse('180').success).toBe(true);
            expect(longitudeSchema.safeParse('-180').success).toBe(true);
        });
    });
});
