import { describe, expect, it } from 'vitest';

import { apiEndpoints, queryKeys } from '@/lib/query-keys';

describe('queryKeys', () => {
    describe('ror', () => {
        it('produces a stable key for all affiliations', () => {
            expect(queryKeys.ror.all()).toEqual(['ror', 'affiliations']);
            expect(queryKeys.ror.all()).toEqual(queryKeys.ror.all());
        });

        it('sorts batch input for resolve keys', () => {
            const a = queryKeys.ror.resolve(['b', 'a', 'c']);
            const b = queryKeys.ror.resolve(['a', 'b', 'c']);

            expect(a).toEqual(['ror', 'resolve', ['a', 'b', 'c']]);
            expect(a).toEqual(b);
        });

        it('does not mutate the caller-provided array', () => {
            const input = ['b', 'a'];
            queryKeys.ror.resolve(input);
            expect(input).toEqual(['b', 'a']);
        });
    });

    describe('doi', () => {
        it('encodes DOI and excludeResourceId', () => {
            const key = queryKeys.doi.validate('10.5880/test', 42);
            expect(key[1]).toBe('10.5880/test');
            expect(key[2]).toBe(42);
        });

        it('uses null when excludeResourceId is not provided', () => {
            const key = queryKeys.doi.validate('10.5880/test');
            expect(key[2]).toBeNull();
        });

        it('shares the same leading URL segment across invocations', () => {
            const a = queryKeys.doi.validate('a');
            const b = queryKeys.doi.validate('b', 1);
            expect(a[0]).toBe(b[0]);
        });
    });

    describe('pid4inst', () => {
        it('produces a stable instruments key', () => {
            expect(queryKeys.pid4inst.instruments()).toEqual(['pid4inst', 'instruments']);
        });
    });

    describe('msl', () => {
        it('produces stable vocabulary-url and laboratories keys', () => {
            expect(queryKeys.msl.vocabularyUrl()).toEqual(['msl', 'vocabulary-url']);
            expect(queryKeys.msl.laboratories()).toEqual(['msl', 'laboratories']);
        });
    });
});

describe('apiEndpoints', () => {
    it('exposes all expected endpoints as non-empty strings', () => {
        expect(apiEndpoints.rorAffiliations).toBe('/api/v1/ror-affiliations');
        expect(apiEndpoints.rorResolve).toBe('/api/v1/ror-resolve');
        expect(typeof apiEndpoints.doiValidate).toBe('string');
        expect(apiEndpoints.doiValidate.length).toBeGreaterThan(0);
        expect(apiEndpoints.pid4instInstruments).toBe('/vocabularies/pid4inst-instruments');
        expect(apiEndpoints.mslVocabularyUrl).toBe('/vocabularies/msl-vocabulary-url');
    });
});
