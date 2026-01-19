import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Editor from '@/pages/editor';
import type { DateType, Language,License, ResourceType, TitleType } from '@/types';

const resourceTypes: ResourceType[] = [{ id: 1, name: 'Dataset' }];
const titleTypes: TitleType[] = [
    { id: 1, name: 'Main Title', slug: 'main-title' },
];
const dateTypes: DateType[] = [
    { id: 1, name: 'Accepted', slug: 'accepted', description: 'The date the publisher accepted the resource.' },
    { id: 2, name: 'Available', slug: 'available', description: 'The date the resource is made publicly available.' },
];
const licenses: License[] = [
    { id: 1, identifier: 'MIT', name: 'MIT License' },
];

const languages: Language[] = [
    { id: 1, code: 'en', name: 'English' },
];

const renderForm = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => ({
        props: {
            auth: {
                user: { id: 1, name: 'Test User', role: 'curator' },
            },
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/curation/datacite-form', () => ({
    default: (props: unknown) => {
        renderForm(props);
        return <div data-testid="datacite-form" />;
    },
}));

describe('Editor page', () => {
    beforeEach(() => {
        renderForm.mockClear();
        vi.stubGlobal(
            'fetch',
            vi.fn((url: RequestInfo) =>
                Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve(
                            url.toString().includes('resource-types')
                                ? resourceTypes
                                : url.toString().includes('title-types')
                                  ? titleTypes
                                  : url.toString().includes('date-types')
                                    ? dateTypes
                                    : url.toString().includes('licenses')
                                      ? licenses
                                      : languages,
                        ),
                }),
            ),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('fetches resource types and passes data to DataCiteForm', async () => {
        render(<Editor maxTitles={99} maxLicenses={99} googleMapsApiKey="test-api-key" />);
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ resourceTypes, titleTypes, dateTypes, licenses, languages }),
            ),
        );
    });

    it('shows loading state before types load', () => {
        vi.mocked(fetch).mockImplementation(
            () => new Promise(() => {}),
        );
        render(<Editor maxTitles={99} maxLicenses={99} googleMapsApiKey="test-api-key" />);
        expect(screen.getByRole('status')).toHaveTextContent(
            /loading resource and title types, licenses, languages, and role options/i,
        );
    });

    it('shows loading state when only one type set has loaded', async () => {
        const unresolved = new Promise<Response>(() => {});
        vi.mocked(fetch).mockImplementation((url) =>
            url.toString().includes('resource-types')
                ? Promise.resolve({ ok: true, json: () => Promise.resolve(resourceTypes) } as Response)
                : unresolved,
        );
        render(<Editor maxTitles={99} maxLicenses={99} googleMapsApiKey="test-api-key" />);
        expect(screen.getByRole('status')).toHaveTextContent(
            /loading resource and title types, licenses, languages, and role options/i,
        );
    });

    it('passes limits to DataCiteForm', async () => {
        render(<Editor maxTitles={5} maxLicenses={7} googleMapsApiKey="test-api-key" />);
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ maxTitles: 5, maxLicenses: 7 }),
            ),
        );
    });

    it('passes doi to DataCiteForm when provided', async () => {
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                doi="10.1234/xyz"
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialDoi: '10.1234/xyz' }),
            ),
        );
    });

    it('passes year to DataCiteForm when provided', async () => {
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                year="2024"
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialYear: '2024' }),
            ),
        );
    });

    it('passes version to DataCiteForm when provided', async () => {
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                version="2.0"
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialVersion: '2.0' }),
            ),
        );
    });

    it('passes language to DataCiteForm when provided', async () => {
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                language="de"
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialLanguage: 'de' }),
            ),
        );
    });

    it('passes resource type to DataCiteForm when provided', async () => {
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                resourceType="1"
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialResourceType: '1' }),
            ),
        );
    });

    it('passes titles to DataCiteForm when provided', async () => {
        const titles = [
            { title: 'Main', titleType: 'main-title' },
            { title: 'Alt', titleType: 'alternative-title' },
        ];
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                titles={titles}
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialTitles: titles }),
            ),
        );
    });

    it('passes initial licenses to DataCiteForm when provided', async () => {
        const initialLicenses = ['MIT'];
        render(
            <Editor
                maxTitles={99}
                maxLicenses={99}
                googleMapsApiKey="test-api-key"
                initialLicenses={initialLicenses}
            />,
        );
        await waitFor(() =>
            expect(renderForm).toHaveBeenCalledWith(
                expect.objectContaining({ initialLicenses }),
            ),
        );
    });
});
