import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useAffiliationsTagify } from '@/hooks/use-affiliations-tagify';

describe('useAffiliationsTagify', () => {
    it('returns tagify settings with empty whitelist when no suggestions', () => {
        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations: [],
                idPrefix: 'test',
            }),
        );

        expect(result.current.tagifySettings.whitelist).toEqual([]);
        expect(result.current.tagifySettings.dropdown?.enabled).toBe(0);
    });

    it('returns tagify settings with whitelist from suggestions', () => {
        const suggestions = [
            { value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394', searchTerms: 'German Research Centre' },
            { value: 'MIT', rorId: 'https://ror.org/042nb2s44', searchTerms: 'Massachusetts Institute' },
        ];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: suggestions,
                affiliations: [],
                idPrefix: 'test',
            }),
        );

        expect(result.current.tagifySettings.whitelist).toHaveLength(2);
        expect(result.current.tagifySettings.dropdown?.enabled).toBe(1);
        expect(result.current.tagifySettings.dropdown?.maxItems).toBe(20);
    });

    it('extracts affiliations with ROR IDs', () => {
        const affiliations = [
            { value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394' },
            { value: 'MIT', rorId: 'https://ror.org/042nb2s44' },
        ];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'author-1',
            }),
        );

        expect(result.current.affiliationsWithRorId).toHaveLength(2);
        expect(result.current.affiliationsWithRorId[0].value).toBe('GFZ Potsdam');
        expect(result.current.affiliationsWithRorId[0].rorId).toBe('https://ror.org/04z8jg394');
    });

    it('filters out affiliations without ROR IDs', () => {
        const affiliations = [
            { value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394' },
            { value: 'Unknown Institution', rorId: '' },
            { value: 'Another Unknown', rorId: undefined as unknown as string },
        ];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'test',
            }),
        );

        expect(result.current.affiliationsWithRorId).toHaveLength(1);
        expect(result.current.affiliationsWithRorId[0].value).toBe('GFZ Potsdam');
    });

    it('deduplicates affiliations by ROR ID', () => {
        const affiliations = [
            { value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394' },
            { value: 'GFZ German Research Centre', rorId: 'https://ror.org/04z8jg394' },
        ];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'test',
            }),
        );

        expect(result.current.affiliationsWithRorId).toHaveLength(1);
    });

    it('returns accessibility description ID when affiliations have ROR IDs', () => {
        const affiliations = [{ value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394' }];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'author-123',
            }),
        );

        expect(result.current.affiliationsDescriptionId).toBe('author-123-affiliations-ror-description');
    });

    it('returns undefined description ID when no affiliations have ROR IDs', () => {
        const affiliations = [{ value: 'Unknown Institution', rorId: '' }];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'test',
            }),
        );

        expect(result.current.affiliationsDescriptionId).toBeUndefined();
    });

    it('trims whitespace from affiliation values and ROR IDs', () => {
        const affiliations = [{ value: '  GFZ Potsdam  ', rorId: '  https://ror.org/04z8jg394  ' }];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'test',
            }),
        );

        expect(result.current.affiliationsWithRorId[0].value).toBe('GFZ Potsdam');
        expect(result.current.affiliationsWithRorId[0].rorId).toBe('https://ror.org/04z8jg394');
    });

    it('filters out affiliations with empty values', () => {
        const affiliations = [
            { value: '', rorId: 'https://ror.org/04z8jg394' },
            { value: '  ', rorId: 'https://ror.org/042nb2s44' },
        ];

        const { result } = renderHook(() =>
            useAffiliationsTagify({
                affiliationSuggestions: [],
                affiliations,
                idPrefix: 'test',
            }),
        );

        expect(result.current.affiliationsWithRorId).toHaveLength(0);
    });
});
