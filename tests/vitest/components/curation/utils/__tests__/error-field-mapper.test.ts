import { describe, expect, it } from 'vitest';

import {
    type MappedError,
    extractSectionFromMessage,
    groupErrorsBySection,
    mapBackendErrors,
    stripSectionPrefix,
} from '@/components/curation/utils/error-field-mapper';

describe('extractSectionFromMessage', () => {
    it('extracts section name from prefixed message', () => {
        expect(extractSectionFromMessage('[Resource Information] Publication Year is required.')).toBe(
            'Resource Information',
        );
    });

    it('extracts section name with special characters', () => {
        expect(extractSectionFromMessage('[Licenses & Rights] At least one license is required.')).toBe(
            'Licenses & Rights',
        );
    });

    it('extracts section name from Spatial & Temporal Coverage prefix', () => {
        expect(extractSectionFromMessage('[Spatial & Temporal Coverage] Coverage #1 polygon must have at least 3 points.')).toBe(
            'Spatial & Temporal Coverage',
        );
    });

    it('returns null for messages without prefix', () => {
        expect(extractSectionFromMessage('Publication Year is required.')).toBeNull();
    });

    it('returns null for empty string', () => {
        expect(extractSectionFromMessage('')).toBeNull();
    });

    it('returns null for malformed bracket prefix', () => {
        expect(extractSectionFromMessage('[Unclosed bracket message')).toBeNull();
    });
});

describe('stripSectionPrefix', () => {
    it('strips section prefix from message', () => {
        expect(stripSectionPrefix('[Resource Information] Publication Year is required.')).toBe(
            'Publication Year is required.',
        );
    });

    it('strips section prefix with ampersand', () => {
        expect(stripSectionPrefix('[Licenses & Rights] At least one license is required.')).toBe(
            'At least one license is required.',
        );
    });

    it('returns original message when no prefix found', () => {
        expect(stripSectionPrefix('Publication Year is required.')).toBe('Publication Year is required.');
    });

    it('returns empty string for empty input', () => {
        expect(stripSectionPrefix('')).toBe('');
    });
});

describe('mapBackendErrors', () => {
    it('maps simple top-level key to correct section', () => {
        const errors: Record<string, string[]> = {
            year: ['[Resource Information] Publication Year is required.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].backendKey).toBe('year');
        expect(mapped[0].message).toBe('Publication Year is required.');
        expect(mapped[0].sectionId).toBe('resource-info');
        expect(mapped[0].sectionName).toBe('Resource Information');
        expect(mapped[0].fieldSelector).toBe('#year');
        expect(mapped[0].fieldId).toBe('year');
    });

    it('maps array-indexed key to correct section and selector', () => {
        const errors: Record<string, string[]> = {
            'authors.0.lastName': ['[Authors] Author #1 requires a last name.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].backendKey).toBe('authors.0.lastName');
        expect(mapped[0].sectionId).toBe('authors');
        expect(mapped[0].sectionName).toBe('Authors');
        expect(mapped[0].fieldSelector).toBe('[data-testid="author-0-fields-grid"]');
        expect(mapped[0].fieldId).toBeNull();
    });

    it('maps licenses to correct section', () => {
        const errors: Record<string, string[]> = {
            licenses: ['[Licenses & Rights] At least one license is required.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('licenses-rights');
        expect(mapped[0].sectionName).toBe('Licenses & Rights');
    });

    it('maps contributors to correct section', () => {
        const errors: Record<string, string[]> = {
            'contributors.0.roles': ['[Contributors] Contributor #1 must have at least one role.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('contributors');
        expect(mapped[0].fieldSelector).toBe('[data-testid="contributor-0-type-field"]');
    });

    it('maps descriptions to correct section', () => {
        const errors: Record<string, string[]> = {
            descriptions: ['[Descriptions] An Abstract description is required.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('descriptions');
        expect(mapped[0].sectionName).toBe('Descriptions');
    });

    it('maps spatial temporal coverages to correct section', () => {
        const errors: Record<string, string[]> = {
            'spatialTemporalCoverages.0.polygonPoints': [
                '[Spatial & Temporal Coverage] Coverage #1 polygon must have at least 3 points.',
            ],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('spatial-temporal-coverage');
        expect(mapped[0].sectionName).toBe('Spatial & Temporal Coverage');
    });

    it('maps funding references to correct section', () => {
        const errors: Record<string, string[]> = {
            'fundingReferences.0.funderName': [
                '[Funding References] Funding reference #1 requires a funder name.',
            ],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('funding-references');
        expect(mapped[0].fieldSelector).toBe('[data-testid="funding-reference-0"]');
    });

    it('maps related identifiers to correct section', () => {
        const errors: Record<string, string[]> = {
            'relatedIdentifiers.0.identifier': [
                '[Related Work] Related identifier #1 must not be empty.',
            ],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('related-work');
    });

    it('maps instruments to correct section', () => {
        const errors: Record<string, string[]> = {
            'instruments.0.pid': ['[Used Instruments] Instrument #1 requires a PID.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('used-instruments');
    });

    it('maps gcmdKeywords to controlled-vocabularies section', () => {
        const errors: Record<string, string[]> = {
            'gcmdKeywords.0.id': ['[Controlled Vocabularies] Keyword #1 must have an identifier.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('controlled-vocabularies');
    });

    it('maps freeKeywords to free-keywords section', () => {
        const errors: Record<string, string[]> = {
            'freeKeywords.0': ['[Free Keywords] Keyword #1 exceeds the maximum length of 255 characters.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('free-keywords');
    });

    it('maps dates to dates section', () => {
        const errors: Record<string, string[]> = {
            'dates.0.dateType': ['[Dates] Date #1 must have a type.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('dates');
    });

    it('handles multiple messages for same key', () => {
        const errors: Record<string, string[]> = {
            year: [
                '[Resource Information] Publication Year is required.',
                '[Resource Information] Publication Year must be a number.',
            ],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(2);
        expect(mapped[0].message).toBe('Publication Year is required.');
        expect(mapped[1].message).toBe('Publication Year must be a number.');
    });

    it('handles multiple keys across different sections', () => {
        const errors: Record<string, string[]> = {
            year: ['[Resource Information] Publication Year is required.'],
            licenses: ['[Licenses & Rights] At least one license is required.'],
            authors: ['[Authors] At least one author must be provided.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(3);

        const sections = new Set(mapped.map((e) => e.sectionId));
        expect(sections).toContain('resource-info');
        expect(sections).toContain('licenses-rights');
        expect(sections).toContain('authors');
    });

    it('falls back to "unknown" section for unrecognized key prefix', () => {
        const errors: Record<string, string[]> = {
            unknownField: ['Some unknown error.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped).toHaveLength(1);
        expect(mapped[0].sectionId).toBe('unknown');
        expect(mapped[0].sectionName).toBe('Other');
        expect(mapped[0].fieldSelector).toBeNull();
    });

    it('resolves simple field selectors (doi, year, resourceType)', () => {
        const errors: Record<string, string[]> = {
            doi: ['[Resource Information] This DOI is already in use.'],
            year: ['[Resource Information] Publication Year is required.'],
            resourceType: ['[Resource Information] Resource Type is required.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped.find((e) => e.backendKey === 'doi')?.fieldSelector).toBe('#doi');
        expect(mapped.find((e) => e.backendKey === 'year')?.fieldSelector).toBe('#year');
        expect(mapped.find((e) => e.backendKey === 'resourceType')?.fieldSelector).toBe('#resourceType');
    });

    it('resolves title input selector', () => {
        const errors: Record<string, string[]> = {
            'titles.0.title': ['[Resource Information] Title #1 must not be empty.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped[0].fieldSelector).toBe('[data-testid="main-title-input"]');
    });

    it('resolves "titles" (no index) to main title input', () => {
        const errors: Record<string, string[]> = {
            titles: ['[Resource Information] At least one title is required.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped[0].fieldSelector).toBe('[data-testid="main-title-input"]');
    });

    it('resolves "licenses" (no index) to first license select', () => {
        const errors: Record<string, string[]> = {
            licenses: ['[Licenses & Rights] At least one license is required.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped[0].fieldSelector).toBe('[data-testid="license-select-0"]');
    });

    it('resolves "authors" (no index) to null (accordion section fallback)', () => {
        const errors: Record<string, string[]> = {
            authors: ['[Authors] At least one author must be provided.'],
        };

        const mapped = mapBackendErrors(errors);

        expect(mapped[0].fieldSelector).toBeNull();
    });

    it('returns empty array for empty errors input', () => {
        const mapped = mapBackendErrors({});

        expect(mapped).toEqual([]);
    });
});

describe('groupErrorsBySection', () => {
    it('groups errors by sectionId', () => {
        const errors: MappedError[] = [
            {
                backendKey: 'year',
                message: 'Publication Year is required.',
                sectionId: 'resource-info',
                sectionName: 'Resource Information',
                fieldSelector: '#year',
                fieldId: 'year',
            },
            {
                backendKey: 'titles',
                message: 'At least one title is required.',
                sectionId: 'resource-info',
                sectionName: 'Resource Information',
                fieldSelector: '[data-testid="main-title-input"]',
                fieldId: null,
            },
            {
                backendKey: 'licenses',
                message: 'At least one license is required.',
                sectionId: 'licenses-rights',
                sectionName: 'Licenses & Rights',
                fieldSelector: '[data-testid="license-select-0"]',
                fieldId: null,
            },
        ];

        const grouped = groupErrorsBySection(errors);

        expect(grouped.size).toBe(2);

        const resourceInfoGroup = grouped.get('resource-info');
        expect(resourceInfoGroup).toBeDefined();
        expect(resourceInfoGroup!.sectionName).toBe('Resource Information');
        expect(resourceInfoGroup!.errors).toHaveLength(2);

        const licensesGroup = grouped.get('licenses-rights');
        expect(licensesGroup).toBeDefined();
        expect(licensesGroup!.sectionName).toBe('Licenses & Rights');
        expect(licensesGroup!.errors).toHaveLength(1);
    });

    it('returns empty map for empty input', () => {
        const grouped = groupErrorsBySection([]);

        expect(grouped.size).toBe(0);
    });

    it('preserves section order from first occurrence', () => {
        const errors: MappedError[] = [
            {
                backendKey: 'authors',
                message: 'Missing author',
                sectionId: 'authors',
                sectionName: 'Authors',
                fieldSelector: null,
                fieldId: null,
            },
            {
                backendKey: 'year',
                message: 'Missing year',
                sectionId: 'resource-info',
                sectionName: 'Resource Information',
                fieldSelector: '#year',
                fieldId: 'year',
            },
        ];

        const grouped = groupErrorsBySection(errors);
        const keys = Array.from(grouped.keys());

        expect(keys[0]).toBe('authors');
        expect(keys[1]).toBe('resource-info');
    });
});
