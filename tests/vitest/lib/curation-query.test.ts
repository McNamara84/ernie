import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { __testing,buildCurationQueryFromResource } from '@/lib/curation-query';

const originalFetch = globalThis.fetch;

describe('buildCurationQueryFromResource', () => {
    beforeEach(() => {
        __testing.resetResourceTypeCache();
    });

    afterEach(() => {
        __testing.resetResourceTypeCache();
        if (originalFetch) {
            globalThis.fetch = originalFetch;
        }
        vi.restoreAllMocks();
    });

    it('constructs a query string using available metadata and mapped resource type identifiers', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 7, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            id: 42,
            doi: ' 10.1234/example ',
            year: 2023,
            version: ' 1.0 ',
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: 'en', name: 'English' },
            titles: [
                { title: 'Primary Title', title_type: { slug: 'main-title' } },
                { title: ' Secondary Title ', title_type: { slug: null } },
            ],
            licenses: [
                { identifier: 'CC-BY-4.0' },
                { identifier: null },
            ],
        });

        expect(query).toEqual({
            resourceId: '42',
            doi: '10.1234/example',
            year: '2023',
            version: '1.0',
            language: 'en',
            resourceType: '7',
            'titles[0][title]': 'Primary Title',
            'titles[0][titleType]': 'main-title',
            'titles[1][title]': 'Secondary Title',
            'titles[1][titleType]': 'alternative-title',
            'licenses[0]': 'CC-BY-4.0',
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith('/api/v1/resource-types/ernie');
    });

    it('omits the resource identifier when unavailable or invalid', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 2, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            id: undefined,
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: '', name: '' },
            titles: [{ title: 'Title', title_type: { slug: 'main-title' } }],
            licenses: [],
        });

        expect(query).not.toHaveProperty('resourceId');
        expect(query).toMatchObject({ year: '2024' });
    });

    it('reuses cached resource type data across multiple invocations', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 3, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const resource = {
            doi: '10.0000/example',
            year: 2024,
            version: null,
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: null, name: null },
            titles: [{ title: 'Title', title_type: { slug: 'main-title' } }],
            licenses: [],
        };

        await buildCurationQueryFromResource(resource);
        await buildCurationQueryFromResource(resource);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('omits resource type information when lookup fails gracefully', async () => {
        const error = new Error('network down');
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        const fetchMock = vi.fn(() => Promise.reject(error));
        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2022,
            version: null,
            resource_type: { name: 'Unknown', slug: 'unknown' },
            language: { code: 'de', name: 'German' },
            titles: [],
            licenses: [],
        });

        expect(query).toEqual({
            year: '2022',
            language: 'de',
        });
        expect(consoleSpy).toHaveBeenCalledWith('Failed to fetch resource types for editor.', error);
    });

    it('includes authors and affiliations when provided', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            authors: [
                {
                    type: 'institution',
                    position: '3',
                    institutionName: ' Example Institute ',
                    rorId: ' https://ror.org/0abc12345 ',
                    affiliations: [
                        { value: ' Example Institute ' },
                        { name: ' Example Institute ' },
                    ],
                },
                {
                    type: 'person',
                    position: 1,
                    orcid: ' 0000-0002-1825-0097 ',
                    firstName: ' Ada ',
                    lastName: ' Lovelace ',
                    email: ' ada@example.org ',
                    website: ' https://ada.example.org ',
                    isContact: 'true',
                    affiliations: [
                        { value: ' Example Lab ', rorId: ' https://ror.org/05d7xk087 ' },
                        { identifier: 'https://ror.org/05d7xk087' },
                        { value: ' ' },
                    ],
                },
            ],
        });

        expect(query).toMatchObject({
            'authors[0][type]': 'person',
            'authors[0][position]': '1',
            'authors[0][orcid]': '0000-0002-1825-0097',
            'authors[0][firstName]': 'Ada',
            'authors[0][lastName]': 'Lovelace',
            'authors[0][email]': 'ada@example.org',
            'authors[0][website]': 'https://ada.example.org',
            'authors[0][isContact]': 'true',
            'authors[0][affiliations][0][value]': 'Example Lab',
            'authors[0][affiliations][0][rorId]': 'https://ror.org/05d7xk087',
            'authors[0][affiliations][1][value]': 'https://ror.org/05d7xk087',
            'authors[1][type]': 'institution',
            'authors[1][position]': '3',
            'authors[1][institutionName]': 'Example Institute',
            'authors[1][rorId]': 'https://ror.org/0abc12345',
            'authors[1][affiliations][0][value]': 'Example Institute',
        });

        expect(query).not.toHaveProperty('authors[1][affiliations][0][rorId]');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('includes free keywords when provided', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 5, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: '10.1234/test',
            year: 2025,
            version: '1.0',
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: 'en', name: 'English' },
            titles: [{ title: 'Test', title_type: { slug: 'main-title' } }],
            licenses: [],
            freeKeywords: ['climate change', 'temperature', 'precipitation'],
        });

        expect(query).toMatchObject({
            'freeKeywords[0]': 'climate change',
            'freeKeywords[1]': 'temperature',
            'freeKeywords[2]': 'precipitation',
        });
    });

    it('handles empty free keywords array', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 5, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: '10.1234/test',
            year: 2025,
            version: '1.0',
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: 'en', name: 'English' },
            titles: [{ title: 'Test', title_type: { slug: 'main-title' } }],
            licenses: [],
            freeKeywords: [],
        });

        expect(query).not.toHaveProperty('freeKeywords[0]');
    });

    it('handles missing free keywords property', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 5, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: '10.1234/test',
            year: 2025,
            version: '1.0',
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: 'en', name: 'English' },
            titles: [{ title: 'Test', title_type: { slug: 'main-title' } }],
            licenses: [],
            // freeKeywords not provided
        });

        expect(query).not.toHaveProperty('freeKeywords[0]');
    });

    it('preserves keyword order and content', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 5, name: 'Dataset' }]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: '10.1234/test',
            year: 2025,
            version: '1.0',
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: 'en', name: 'English' },
            titles: [{ title: 'Test', title_type: { slug: 'main-title' } }],
            licenses: [],
            freeKeywords: ['InSAR', 'GNSS', 'CO2 storage', 'pH Level'],
        });

        expect(query['freeKeywords[0]']).toBe('InSAR');
        expect(query['freeKeywords[1]']).toBe('GNSS');
        expect(query['freeKeywords[2]']).toBe('CO2 storage');
        expect(query['freeKeywords[3]']).toBe('pH Level');
    });

    it('includes contributors with roles and affiliations', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            contributors: [
                {
                    type: 'person',
                    position: 1,
                    orcid: ' 0000-0001-1234-5678 ',
                    firstName: ' John ',
                    lastName: ' Doe ',
                    roles: ['DataCollector', { name: 'Editor' }, null, ''],
                    affiliations: [{ value: 'University A', rorId: 'https://ror.org/123' }],
                },
                {
                    type: 'institution',
                    position: 2,
                    institutionName: ' Research Center ',
                    roles: [{ name: 'HostingInstitution' }],
                    affiliations: [],
                },
            ],
        });

        expect(query).toMatchObject({
            'contributors[0][type]': 'person',
            'contributors[0][position]': '1',
            'contributors[0][orcid]': '0000-0001-1234-5678',
            'contributors[0][firstName]': 'John',
            'contributors[0][lastName]': 'Doe',
            'contributors[0][roles][0]': 'DataCollector',
            'contributors[0][roles][1]': 'Editor',
            'contributors[0][affiliations][0][value]': 'University A',
            'contributors[0][affiliations][0][rorId]': 'https://ror.org/123',
            'contributors[1][type]': 'institution',
            'contributors[1][position]': '2',
            'contributors[1][institutionName]': 'Research Center',
            'contributors[1][roles][0]': 'HostingInstitution',
        });
    });

    it('includes descriptions with kebab-to-pascal case conversion', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            descriptions: [
                { descriptionType: 'abstract', description: ' This is an abstract. ' },
                { descriptionType: 'methods', description: 'Methods description' },
                { descriptionType: 'technical-info', description: 'Technical information' },
                { descriptionType: null, description: 'Should be skipped' },
                { descriptionType: 'abstract', description: null },
                null,
            ],
        });

        expect(query).toMatchObject({
            'descriptions[0][type]': 'Abstract',
            'descriptions[0][description]': 'This is an abstract.',
            'descriptions[1][type]': 'Methods',
            'descriptions[1][description]': 'Methods description',
            'descriptions[2][type]': 'TechnicalInfo',
            'descriptions[2][description]': 'Technical information',
        });

        // Should not include skipped items
        expect(query).not.toHaveProperty('descriptions[3][type]');
    });

    it('includes dates with all fields', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            dates: [
                {
                    dateType: 'collected',
                    startDate: '2024-01-01',
                    endDate: '2024-12-31',
                    dateInformation: ' Additional info ',
                },
                {
                    dateType: 'created',
                    startDate: '2024-06-15',
                },
                { dateType: null, startDate: '2024-01-01' },
                null,
            ],
        });

        expect(query).toMatchObject({
            'dates[0][dateType]': 'collected',
            'dates[0][startDate]': '2024-01-01',
            'dates[0][endDate]': '2024-12-31',
            'dates[0][dateInformation]': 'Additional info',
            'dates[1][dateType]': 'created',
            'dates[1][startDate]': '2024-06-15',
        });

        // Should not have endDate for second date or third date at all
        expect(query).not.toHaveProperty('dates[1][endDate]');
        expect(query).not.toHaveProperty('dates[2][dateType]');
    });

    it('includes controlled keywords (GCMD vocabularies)', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            controlledKeywords: [
                {
                    id: 'gcmd-123',
                    text: 'EARTH SCIENCE > ATMOSPHERE',
                    path: 'Earth Science/Atmosphere',
                    language: 'en',
                    scheme: 'GCMD',
                    schemeURI: 'https://gcmd.nasa.gov/',
                },
            ],
        });

        expect(query).toMatchObject({
            'gcmdKeywords[0][id]': 'gcmd-123',
            'gcmdKeywords[0][text]': 'EARTH SCIENCE > ATMOSPHERE',
            'gcmdKeywords[0][path]': 'Earth Science/Atmosphere',
            'gcmdKeywords[0][language]': 'en',
            'gcmdKeywords[0][scheme]': 'GCMD',
            'gcmdKeywords[0][schemeURI]': 'https://gcmd.nasa.gov/',
        });
    });

    it('includes spatial temporal coverages', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            spatialTemporalCoverages: [
                {
                    latMin: '45.0',
                    latMax: '55.0',
                    lonMin: '5.0',
                    lonMax: '15.0',
                    startDate: '2024-01-01',
                    endDate: '2024-12-31',
                    startTime: '08:00',
                    endTime: '18:00',
                    timezone: 'UTC',
                    description: 'Study area in Central Europe',
                },
                {
                    latMin: '',
                    latMax: '',
                    lonMin: '',
                    lonMax: '',
                    startDate: '',
                    endDate: '',
                    startTime: '',
                    endTime: '',
                    timezone: '',
                    description: '',
                },
            ],
        });

        expect(query).toMatchObject({
            'coverages[0][latMin]': '45.0',
            'coverages[0][latMax]': '55.0',
            'coverages[0][lonMin]': '5.0',
            'coverages[0][lonMax]': '15.0',
            'coverages[0][startDate]': '2024-01-01',
            'coverages[0][endDate]': '2024-12-31',
            'coverages[0][startTime]': '08:00',
            'coverages[0][endTime]': '18:00',
            'coverages[0][timezone]': 'UTC',
            'coverages[0][description]': 'Study area in Central Europe',
        });

        // Second coverage with empty values should not have those keys
        expect(query).not.toHaveProperty('coverages[1][latMin]');
    });

    it('includes related identifiers as JSON string', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const relatedIdentifiers = [
            {
                identifier: '10.5880/related.001',
                identifierType: 'DOI',
                relationType: 'IsCitedBy',
                position: 0,
            },
            {
                identifier: 'https://example.org/paper',
                identifierType: 'URL',
                relationType: 'IsDescribedBy',
                position: 1,
            },
        ];

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            relatedIdentifiers,
        });

        expect(query.relatedWorks).toBe(JSON.stringify(relatedIdentifiers));
    });

    it('omits relatedWorks when no related identifiers provided', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            relatedIdentifiers: [],
        });

        expect(query).not.toHaveProperty('relatedWorks');
    });

    it('includes funding references as JSON string', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const fundingReferences = [
            {
                funderName: 'DFG',
                funderIdentifier: 'https://ror.org/dfg123',
                funderIdentifierType: 'ROR',
                awardNumber: 'GRANT-123',
                awardUri: 'https://dfg.de/grant/123',
                awardTitle: 'Climate Research',
                position: 0,
            },
        ];

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            fundingReferences,
        });

        expect(query.fundingReferences).toBe(JSON.stringify(fundingReferences));
    });

    it('omits fundingReferences when no funding references provided', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            fundingReferences: [],
        });

        expect(query).not.toHaveProperty('fundingReferences');
    });

    it('includes MSL laboratories as JSON string', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const mslLaboratories = [
            {
                identifier: 'msl-lab-1',
                name: 'Rock Mechanics Lab',
                affiliation_name: 'GFZ Potsdam',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ];

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            mslLaboratories,
        });

        expect(query.mslLaboratories).toBe(JSON.stringify(mslLaboratories));
    });

    it('omits mslLaboratories when no labs provided', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            mslLaboratories: [],
        });

        expect(query).not.toHaveProperty('mslLaboratories');
    });

    it('handles non-ok response from resource types API', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.resolve({ error: 'Server error' }),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
        });

        // Should not include resourceType when API fails
        expect(query).not.toHaveProperty('resourceType');
    });

    it('handles duplicate contributor roles', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            contributors: [
                {
                    type: 'person',
                    position: 1,
                    firstName: 'Jane',
                    lastName: 'Smith',
                    roles: ['Editor', 'Editor', { name: 'Editor' }, 'DataCurator'],
                },
            ],
        });

        // Duplicate roles should be deduplicated
        expect(query['contributors[0][roles][0]']).toBe('Editor');
        expect(query['contributors[0][roles][1]']).toBe('DataCurator');
        expect(query).not.toHaveProperty('contributors[0][roles][2]');
    });

    it('handles null and undefined contributors gracefully', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            contributors: [null, undefined, { type: 'person', position: 1, firstName: 'Valid' }],
        });

        // Only valid contributor should be included
        expect(query['contributors[0][type]']).toBe('person');
        expect(query['contributors[0][firstName]']).toBe('Valid');
    });

    it('sorts contributors by position', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve([]),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: null, slug: null },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
            contributors: [
                { type: 'person', position: 3, firstName: 'Third' },
                { type: 'person', position: 1, firstName: 'First' },
                { type: 'person', position: 2, firstName: 'Second' },
            ],
        });

        expect(query['contributors[0][firstName]']).toBe('First');
        expect(query['contributors[1][firstName]']).toBe('Second');
        expect(query['contributors[2][firstName]']).toBe('Third');
    });

    it('handles non-array resource type response', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ types: [{ id: 1, name: 'Dataset' }] }),
            } as unknown as Response),
        );

        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const query = await buildCurationQueryFromResource({
            doi: null,
            year: 2024,
            version: null,
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { code: null, name: null },
            titles: [],
            licenses: [],
        });

        // Should not include resourceType when response is not an array
        expect(query).not.toHaveProperty('resourceType');
    });
});
