import { render, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { beforeEach,describe, expect, it, vi } from 'vitest';

import OldDatasets from '@/pages/old-datasets';

// Mock Inertia Router mit vi.hoisted
const { routerGetMock } = vi.hoisted(() => ({
    routerGetMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: {
        get: routerGetMock,
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="mock-app-layout">{children}</div>
    ),
}));

describe('OldDataset Authors Loading', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.clearAllMocks();

        // Mock fetch für API-Aufrufe (Resource Types, Licenses)
        global.fetch = vi.fn((url: string) => {
            if (url.includes('/api/v1/resource-types/ernie')) {
                return Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve([
                            { id: 1, name: 'Audiovisual', slug: 'audiovisual' },
                            { id: 10, name: 'Dataset', slug: 'dataset' },
                            { id: 13, name: 'Image', slug: 'image' },
                        ]),
                } as Response);
            }
            if (url.includes('/api/v1/licenses/ernie')) {
                return Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve([
                            { id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0' },
                            { id: 2, identifier: 'MIT', name: 'MIT License' },
                            { id: 3, identifier: 'GPL-3.0', name: 'GNU General Public License v3.0' },
                        ]),
                } as Response);
            }
            return Promise.reject(new Error('Unknown URL'));
        }) as unknown as typeof fetch;

        // Mock IntersectionObserver
        global.IntersectionObserver = class MockIntersectionObserver {
            observe = vi.fn();
            disconnect = vi.fn();
            unobserve = vi.fn();
            takeRecords = vi.fn(() => []);
            root = null;
            rootMargin = '';
            thresholds = [];
        } as unknown as typeof IntersectionObserver;
    });

    it('lädt Autoren mit CP-Checkbox und Kontaktinfo korrekt in Query String', async () => {
        const user = userEvent.setup();

        // Mock axios.get für Autoren-API
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                authors: [
                    {
                        name: 'Läuchli, Charlotte',
                        givenName: 'Charlotte',
                        familyName: 'Läuchli',
                        affiliations: [
                            { value: 'Universität Zürich', rorId: 'https://ror.org/03yrm5c26' },
                        ],
                        roles: ['Creator', 'ContactPerson'],
                        isContact: true,
                        email: 'charlotte.laeuchli@example.org',
                        website: 'https://laeuchli.example.org',
                        orcid: '0000-0002-1234-5678',
                        orcidType: 'ORCID',
                    },
                    {
                        name: 'Mustermann, Max',
                        givenName: 'Max',
                        familyName: 'Mustermann',
                        affiliations: [],
                        roles: ['Creator'],
                        isContact: false,
                        email: null,
                        website: null,
                        orcid: null,
                        orcidType: null,
                    },
                ],
            },
        });

        const dataset = {
            id: 2343,
            identifier: '10.1234/example',
            title: 'Test Dataset mit Charlotte Läuchli',
            resourcetypegeneral: 'Dataset',
            curator: 'Charlotte Läuchli',
            created_at: '2024-01-01T10:00:00Z',
            updated_at: '2024-01-02T10:00:00Z',
            publicstatus: 'published',
            publisher: 'Example Publisher',
            publicationyear: 2024,
        };

        const { container } = render(
            <OldDatasets
                datasets={[dataset]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
            />
        );

        // Finde "Open in Editor" Button für den Datensatz
        const button = container.querySelector('button[aria-label*="Open dataset"]');
        expect(button).toBeTruthy();

        // Klick auf den Button
        await user.click(button!);

        // Warte darauf, dass router.get aufgerufen wurde
        await waitFor(() => {
            expect(routerGetMock).toHaveBeenCalled();
        });

        // Prüfe, dass router.get mit einer URL aufgerufen wurde
        const routerCall = routerGetMock.mock.calls[0];
        expect(routerCall).toBeDefined();
        
        const url = routerCall[0]; // Erste Parameter ist die URL
        expect(url).toContain('/editor');
        
        // Prüfe, dass die URL Charlotte Läuchli als Autorin enthält
        expect(url).toContain('authors%5B0%5D%5BfirstName%5D=Charlotte');
        expect(url).toContain('authors%5B0%5D%5BlastName%5D=L%C3%A4uchli');
        expect(url).toContain('authors%5B0%5D%5BisContact%5D=true');
        expect(url).toContain('authors%5B0%5D%5Bemail%5D=charlotte.laeuchli%40example.org');
        expect(url).toContain('authors%5B0%5D%5Bwebsite%5D=https%3A%2F%2Flaeuchli.example.org');
        expect(url).toContain('authors%5B0%5D%5Borcid%5D=0000-0002-1234-5678');
        // Leerzeichen können als + oder %20 kodiert werden
        expect(url).toMatch(/authors%5B0%5D%5Baffiliations%5D%5B0%5D%5Bvalue%5D=Universit%C3%A4t(\+|%20)Z%C3%BCrich/);
        expect(url).toContain('authors%5B0%5D%5Baffiliations%5D%5B0%5D%5BrorId%5D=https%3A%2F%2Fror.org%2F03yrm5c26');

        // Prüfe Max Mustermann (Index 1, aber isContact sollte nicht gesetzt sein)
        expect(url).toContain('authors%5B1%5D%5BfirstName%5D=Max');
        expect(url).toContain('authors%5B1%5D%5BlastName%5D=Mustermann');
        expect(url).not.toContain('authors%5B1%5D%5Borcid%5D');
    });

    it('behandelt Autoren ohne Kontaktinfo korrekt', async () => {
        const user = userEvent.setup();

        // Mock axios.get für Autoren ohne Kontaktinfo
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                authors: [
                    {
                        name: 'Doe, John',
                        givenName: 'John',
                        familyName: 'Doe',
                        affiliations: [],
                        roles: ['Creator'],
                        isContact: false,
                        email: null,
                        website: null,
                        orcid: null,
                        orcidType: null,
                    },
                ],
            },
        });

        const dataset = {
            id: 1,
            identifier: '10.1234/test',
            title: 'Test Dataset',
            resourcetypegeneral: 'Dataset',
            curator: 'Test',
            created_at: '2024-01-01T10:00:00Z',
            updated_at: '2024-01-02T10:00:00Z',
            publicstatus: 'published',
            publisher: 'Test Publisher',
            publicationyear: 2024,
        };

        const { container } = render(
            <OldDatasets
                datasets={[dataset]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
            />
        );

        const button = container.querySelector('button[aria-label*="Open dataset"]');
        await user.click(button!);

        await waitFor(() => {
            expect(routerGetMock).toHaveBeenCalled();
        });

        const url = routerGetMock.mock.calls[0][0];
        
        expect(url).toContain('authors%5B0%5D%5BfirstName%5D=John');
        expect(url).toContain('authors%5B0%5D%5BlastName%5D=Doe');
        expect(url).not.toContain('authors%5B0%5D%5BisContact%5D');
        expect(url).not.toContain('authors%5B0%5D%5Bemail%5D');
        expect(url).not.toContain('authors%5B0%5D%5Bwebsite%5D');
    });

    it('lädt und kodiert ORCID-Daten korrekt', async () => {
        const user = userEvent.setup();

        // Mock axios.get für Autoren mit verschiedenen Identifier-Typen
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                authors: [
                    {
                        name: 'Almqvist, Bjarne',
                        givenName: 'Bjarne',
                        familyName: 'Almquist',
                        affiliations: [
                            { value: 'Uppsala University', rorId: null },
                        ],
                        roles: ['Creator', 'DataCollector'],
                        isContact: false,
                        email: null,
                        website: null,
                        orcid: '0000-0002-9385-7614',
                        orcidType: 'ORCID',
                    },
                    {
                        name: 'Conze, Ronald',
                        givenName: null,
                        familyName: null,
                        affiliations: [
                            { value: 'GFZ Potsdam', rorId: 'https://ror.org/04z8jg394' },
                        ],
                        roles: ['Creator', 'DataManager'],
                        isContact: false,
                        email: null,
                        website: null,
                        orcid: '0000-0002-8209-6290',
                        orcidType: 'ORCID',
                    },
                    {
                        name: 'Smith, Jane',
                        givenName: 'Jane',
                        familyName: 'Smith',
                        affiliations: [],
                        roles: ['Creator'],
                        isContact: false,
                        email: null,
                        website: null,
                        orcid: null,
                        orcidType: 'ScopusID', // Hat einen anderen Identifier-Typ, aber kein ORCID
                    },
                ],
            },
        });

        const dataset = {
            id: 3,
            identifier: '10.5880/GFZ.4.2.2014.001',
            title: 'COSC-1 borehole magnetic data',
            resourcetypegeneral: 'Dataset',
            curator: 'Bjarne Almqvist',
            created_at: '2024-01-01T10:00:00Z',
            updated_at: '2024-01-02T10:00:00Z',
            publicstatus: 'published',
            publisher: 'GFZ Data Services',
            publicationyear: 2024,
        };

        const { container } = render(
            <OldDatasets
                datasets={[dataset]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
            />
        );

        const button = container.querySelector('button[aria-label*="Open dataset"]');
        await user.click(button!);

        await waitFor(() => {
            expect(routerGetMock).toHaveBeenCalled();
        });

        const url = routerGetMock.mock.calls[0][0];

        // Prüfe Bjarne Almqvist mit ORCID
        expect(url).toContain('authors%5B0%5D%5BfirstName%5D=Bjarne');
        expect(url).toContain('authors%5B0%5D%5BlastName%5D=Almquist');
        expect(url).toContain('authors%5B0%5D%5Borcid%5D=0000-0002-9385-7614');

        // Prüfe Ronald Conze mit ORCID
        expect(url).toContain('authors%5B1%5D%5Borcid%5D=0000-0002-8209-6290');

        // Prüfe Jane Smith ohne ORCID (hat ScopusID, aber das wird nicht in orcid-Feld übernommen)
        expect(url).toContain('authors%5B2%5D%5BfirstName%5D=Jane');
        expect(url).toContain('authors%5B2%5D%5BlastName%5D=Smith');
        expect(url).not.toContain('authors%5B2%5D%5Borcid%5D');
    });

    it('behandelt Fehler beim Laden von Autoren gracefully', async () => {
        const user = userEvent.setup();
        const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        // Mock axios.get mit Fehler
        vi.spyOn(axios, 'get').mockRejectedValue(new Error('Network error'));

        const dataset = {
            id: 999,
            identifier: '10.1234/error',
            title: 'Error Dataset',
            resourcetypegeneral: 'Dataset',
            curator: 'Test',
            created_at: '2024-01-01T10:00:00Z',
            updated_at: '2024-01-02T10:00:00Z',
            publicstatus: 'published',
            publisher: 'Test Publisher',
            publicationyear: 2024,
        };

        const { container } = render(
            <OldDatasets
                datasets={[dataset]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
            />
        );

        const button = container.querySelector('button[aria-label*="Open dataset"]');
        await user.click(button!);

        await waitFor(() => {
            expect(routerGetMock).toHaveBeenCalled();
        });

        // Prüfe, dass Fehler geloggt wurde
        expect(consoleErrorSpy).toHaveBeenCalledWith(
            'Error loading authors for dataset:',
            expect.any(Error)
        );

        // Prüfe, dass Query trotzdem erstellt wurde (ohne Autoren)
        const url = routerGetMock.mock.calls[0][0];
        expect(url).toContain('doi=10.1234%2Ferror');
        expect(url).not.toContain('authors%5B0%5D');

        consoleErrorSpy.mockRestore();
    });
});

