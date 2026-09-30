import { describe, expect, it } from 'vitest';

import { canonicalRorId, indexRorSuggestions, parseRorInput, resolveRorInput } from '@/lib/ror-input';

const url = 'https://ror.org/04z8jg394';
const index = indexRorSuggestions([{ value: 'GFZ', rorId: url, searchTerms: ['Potsdam'] }]);

describe('ROR entry parsing and exact resolution', () => {
    it.each(['04z8jg394', url, ` ${url}/ `, 'HTTP://ROR.ORG/04Z8JG394', 'ror.org/04z8jg394', 'www.ror.org/04z8jg394'])('normalizes %s', (input) => {
        expect(canonicalRorId(input)).toBe(url);
        expect(resolveRorInput(parseRorInput(input), index)).toMatchObject({ value: 'GFZ', rorId: url });
    });
    it.each(['GFZ (04z8jg394)', `GFZ (${url})`, ` GFZ (${url}) `])('preserves the supplied name in %s', (input) => {
        expect(parseRorInput(input)).toEqual({ kind: 'ror', name: 'GFZ', rorId: url });
    });
    it('preserves commas, inner parentheses and spelling even when the name disagrees with the directory', () => {
        expect(resolveRorInput(parseRorInput('My Institute (Unit A),  Berlin (04z8jg394)'), index)).toMatchObject({
            value: 'My Institute (Unit A),  Berlin',
            rorId: url,
        });
    });
    it.each(['', 'DFG', 'Institution A, Institution B', 'Institute (Department)', 'Deutsche Forschungsgemeinschaft (DFG)', 'University 123'])(
        'keeps ordinary names: %s',
        (name) => {
            expect(parseRorInput(name)).toEqual({ kind: 'name', name });
        },
    );
    it.each([
        `${url}?query=yes`,
        `${url}#fragment`,
        `${url}/extra`,
        'https://ror.org.evil.example/04z8jg394',
        'https://evil.example/04z8jg394',
        '04z8jgi94',
        '04z8jgl94',
        '04z8jgo94',
        '04z8jgu94',
        'test',
    ])('rejects invalid identifiers: %s', (input) => {
        expect(canonicalRorId(input)).toBeNull();
    });
    it('does not fall back to a matching name for an unknown identifier', () => {
        expect(resolveRorInput(parseRorInput('GFZ (012345678)'), index)).toBeNull();
    });
    it('retains the name for an explicit unlinked fallback after a malformed ROR URL', () => {
        expect(parseRorInput('My Institute (https://ror.org/bad)')).toEqual({ kind: 'invalid', name: 'My Institute' });
    });
    it('excludes malformed or missing catalogue identifiers from the exact index', () => {
        expect(
            indexRorSuggestions([
                { value: 'Bad', rorId: null, searchTerms: [] },
                { value: 'Bad', rorId: '12345', searchTerms: [] },
            ]).size,
        ).toBe(0);
    });
});
