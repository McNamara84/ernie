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
        } as const;

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
        expect(consoleSpy).toHaveBeenCalledWith('Failed to fetch resource types for curation.', error);
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
});
