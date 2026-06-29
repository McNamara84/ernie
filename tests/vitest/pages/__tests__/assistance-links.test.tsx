import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { BaseSuggestionItem, PaginatedData, SuggestedOrcidItem, SuggestedRorItem, SuggestedSpdxRightsItem } from '@/types/assistance';

// ── Mocks ────────────────────────────────────────────────────────────

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    usePage: () => ({ props: {} }),
    router: { reload: vi.fn(), get: vi.fn() },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

vi.mock('axios', () => {
    const post = vi.fn();
    const get = vi.fn();
    const isAxiosError = vi.fn((value: unknown): value is { isAxiosError: true } => {
        return typeof value === 'object' && value !== null && (value as { isAxiosError?: boolean }).isAxiosError === true;
    });

    return { default: { post, get, isAxiosError }, post, get, isAxiosError };
});

const mockedAxiosPost = axios.post as Mock;

// ── Import component under test (after mocks) ───────────────────────

// The card components are not exported individually, so we render the
// full page with minimal props and assert on the rendered output.
// We import the default export (AssistancePage).
import AssistancePage from '@/pages/assistance';

// ── Fixtures ─────────────────────────────────────────────────────────

const SPDX_ASSISTANT_ID = 'spdx-license-suggestion';
const SPDX_ROUTE_PREFIX = 'spdx-rights';
const SPDX_ASSISTANT_NAME = 'SPDX Rights Suggestions';
const SIZE_FORMAT_ASSISTANT_ID = 'size-format-suggestion';
const SIZE_FORMAT_ROUTE_PREFIX = 'size-format';
const SIZE_FORMAT_ASSISTANT_NAME = 'Size and Format Suggestions';

beforeEach(() => {
    mockedAxiosPost.mockReset();
});

function makeOrcidSuggestion(overrides: Partial<SuggestedOrcidItem> = {}): SuggestedOrcidItem {
    return {
        id: 1,
        resource_id: 10,
        resource_doi: '10.5880/test.2024.001',
        resource_title: 'Test Resource',
        person_id: 100,
        person_name: 'Jane Doe',
        person_affiliations: ['GFZ Potsdam'],
        source_context: 'creator',
        suggested_orcid: '0000-0001-2345-6789',
        similarity_score: 0.85,
        candidate_first_name: 'Jane',
        candidate_last_name: 'Doe',
        candidate_affiliations: ['GFZ Helmholtz Centre Potsdam'],
        discovered_at: '2024-06-15T10:00:00+00:00',
        ...overrides,
    };
}

function makeRorSuggestion(overrides: Partial<SuggestedRorItem> = {}): SuggestedRorItem {
    return {
        id: 2,
        resource_id: 20,
        resource_doi: '10.5880/test.2024.002',
        resource_title: 'Another Resource',
        entity_type: 'affiliation',
        entity_id: 200,
        entity_name: 'GFZ Potsdam',
        suggested_ror_id: 'https://ror.org/04t3en479',
        suggested_name: 'GFZ German Research Centre for Geosciences',
        similarity_score: 0.92,
        ror_aliases: ['GFZ Potsdam', 'Helmholtz-Zentrum Potsdam'],
        existing_identifier: null,
        existing_identifier_type: null,
        discovered_at: '2024-06-15T10:00:00+00:00',
        ...overrides,
    };
}

function makeSpdxRightsSuggestion(overrides: Partial<SuggestedSpdxRightsItem> = {}): SuggestedSpdxRightsItem {
    return {
        id: 3,
        resource_id: 30,
        resource_doi: '10.5880/fidgeo.2017.003',
        resource_title: 'FID GEO example resource',
        target_type: 'resource_right',
        target_id: 300,
        suggested_value: 'CC-BY-4.0',
        suggested_label: 'Creative Commons Attribution 4.0 International',
        similarity_score: 0.98,
        metadata: {
            current: {
                rights: 'CC BY 4.0',
                rights_uri: 'http://creativecommons.org/licenses/by/4.0',
                source: 'datacite-import',
            },
            proposed: {
                rights: 'Creative Commons Attribution 4.0 International',
                rights_uri: 'https://creativecommons.org/licenses/by/4.0/',
                scheme_uri: 'https://spdx.org/licenses/',
                rights_identifier: 'CC-BY-4.0',
                rights_identifier_scheme: 'SPDX',
                language: 'en',
            },
            source: 'spdx',
            source_url: 'https://spdx.org/licenses/CC-BY-4.0.html',
            evidence: {
                matched_from: 'rights',
                reason: 'Alias matched normalized SPDX license name.',
            },
        },
        discovered_at: '2024-06-15T10:00:00+00:00',
        ...overrides,
    };
}

function makeSizeFormatSuggestion(overrides: Partial<BaseSuggestionItem> = {}): BaseSuggestionItem {
    return {
        id: 4,
        resource_id: 40,
        resource_doi: '10.5880/test.2026.004',
        resource_title: 'Size and format example resource',
        target_type: 'format',
        target_id: 40,
        suggested_value: 'application/zip',
        suggested_label: 'ZIP archive (download package)',
        metadata: null,
        discovered_at: '2024-06-15T10:00:00+00:00',
        ...overrides,
    };
}

function makeManifest(id: string, routePrefix: string, name: string) {
    return {
        id,
        name,
        description: `${name} description`,
        icon: 'User',
        version: '1.0.0',
        routePrefix,
        sortOrder: id === 'orcid-suggestion' ? 20 : id === 'ror-suggestion' ? 30 : 10,
        statusLabels: {
            checking: 'Starting...',
            completed_with_results: '{count} found.',
            completed_empty: 'No results.',
            failed: 'Failed.',
            already_running: 'Already running.',
        },
        emptyState: { title: 'No suggestions', description: 'Click Check to search.' },
        cardComponent: null,
    };
}

function paginated<T>(data: T[]): PaginatedData<BaseSuggestionItem> {
    return {
        data: data as unknown as BaseSuggestionItem[],
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: data.length,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
        links: [],
    };
}

// ── Tests ────────────────────────────────────────────────────────────

describe('OrcidSuggestionCard – ORCID link', () => {
    it('renders the suggested ORCID as a clickable link', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '0000-0001-2345-6789' });
        expect(link).toBeInTheDocument();
    });

    it('links to the correct orcid.org profile URL', () => {
        const suggestion = makeOrcidSuggestion({ suggested_orcid: '0000-0002-9999-0002' });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '0000-0002-9999-0002' });
        expect(link).toHaveAttribute('href', 'https://orcid.org/0000-0002-9999-0002');
    });

    it('opens the ORCID profile in a new tab', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '0000-0001-2345-6789' });
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('includes noopener noreferrer for security', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '0000-0001-2345-6789' });
        const rel = link.getAttribute('rel') ?? '';
        expect(rel).toContain('noopener');
        expect(rel).toContain('noreferrer');
    });

    it('displays only the ORCID ID as link text (not the full URL)', () => {
        const orcid = '0000-0003-1111-222X';
        const suggestion = makeOrcidSuggestion({ suggested_orcid: orcid });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: orcid });
        expect(link).toHaveTextContent(orcid);
        expect(link).not.toHaveTextContent('https://orcid.org/');
    });

    it('renders multiple ORCID suggestions with unique links', () => {
        const suggestions = [
            makeOrcidSuggestion({ id: 1, suggested_orcid: '0000-0001-0000-0009' }),
            makeOrcidSuggestion({ id: 2, suggested_orcid: '0000-0002-0000-0006', person_name: 'John Smith' }),
        ];

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated(suggestions) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link1 = screen.getByRole('link', { name: '0000-0001-0000-0009' });
        const link2 = screen.getByRole('link', { name: '0000-0002-0000-0006' });

        expect(link1).toHaveAttribute('href', 'https://orcid.org/0000-0001-0000-0009');
        expect(link2).toHaveAttribute('href', 'https://orcid.org/0000-0002-0000-0006');
    });

    it('renders plain text instead of a link for a malformed ORCID ID', () => {
        const suggestion = makeOrcidSuggestion({ suggested_orcid: 'not-a-valid-orcid' });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: 'not-a-valid-orcid' })).not.toBeInTheDocument();
        expect(screen.getByText(/not-a-valid-orcid/)).toBeInTheDocument();
    });

    it('renders plain text instead of a link for an ORCID containing script injection', () => {
        const suggestion = makeOrcidSuggestion({ suggested_orcid: '"><script>alert(1)</script>' });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: /script/ })).not.toBeInTheDocument();
    });

    it('renders plain text for an ORCID that matches the format but fails the checksum', () => {
        // 0000-0001-2345-6780 has valid format but invalid checksum (correct would be 6789)
        const suggestion = makeOrcidSuggestion({ suggested_orcid: '0000-0001-2345-6780' });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: '0000-0001-2345-6780' })).not.toBeInTheDocument();
        expect(screen.getByText(/0000-0001-2345-6780/)).toBeInTheDocument();
    });
});

describe('SpdxRightsSuggestionCard - SPDX preview', () => {
    it('shows imported rights next to the proposed SPDX metadata', () => {
        const suggestion = makeSpdxRightsSuggestion();

        render(
            <AssistancePage
                sections={{ [SPDX_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SPDX_ASSISTANT_ID, SPDX_ROUTE_PREFIX, SPDX_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Current imported rights')).toBeInTheDocument();
        expect(screen.getByText('Proposed SPDX metadata')).toBeInTheDocument();
        expect(screen.getByText('CC BY 4.0')).toBeInTheDocument();
        expect(screen.getByText('Creative Commons Attribution 4.0 International')).toBeInTheDocument();
        expect(screen.getAllByText('CC-BY-4.0')).not.toHaveLength(0);
        expect(screen.getByText('https://spdx.org/licenses/')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'SPDX reference' })).toHaveAttribute(
            'href',
            'https://spdx.org/licenses/CC-BY-4.0.html',
        );
        expect(screen.getByText(/Clicking Accept links only this rights statement/)).toBeInTheDocument();
    });

    it('shows empty metadata fallbacks when SPDX suggestion metadata is absent', () => {
        const suggestion = makeSpdxRightsSuggestion({
            similarity_score: null,
            metadata: null,
        } as Partial<SuggestedSpdxRightsItem>);

        render(
            <AssistancePage
                sections={{ [SPDX_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SPDX_ASSISTANT_ID, SPDX_ROUTE_PREFIX, SPDX_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getAllByText('No metadata captured.')).toHaveLength(2);
        expect(screen.queryByText(/% match/)).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'SPDX reference' })).not.toBeInTheDocument();
    });

    it('posts accept and decline requests through the manifest route prefix', async () => {
        const suggestion = makeSpdxRightsSuggestion({ id: 42 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({ data: { success: true, message: 'SPDX suggestion accepted.' } })
            .mockResolvedValueOnce({ data: {} });

        render(
            <AssistancePage
                sections={{ [SPDX_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SPDX_ASSISTANT_ID, SPDX_ROUTE_PREFIX, SPDX_ASSISTANT_NAME)]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/spdx-rights/42/accept');
            expect(screen.getByRole('button', { name: 'Accept' })).not.toBeDisabled();
        });

        await user.click(screen.getByRole('button', { name: 'Decline' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/spdx-rights/42/decline');
        });
    });
});

describe('SizeFormatSuggestionCard - size and format preview', () => {
    it('highlights ZIP archive suggestions as review-sensitive download packages', () => {
        const suggestion = makeSizeFormatSuggestion();

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('ZIP Archive')).toHaveClass('bg-orange-600');
        expect(screen.getByText('Suggested format: ZIP archive (application/zip)')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Accept' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Decline' })).toBeInTheDocument();
    });

    it('renders non-ZIP format suggestions with their target type and label', () => {
        const suggestion = makeSizeFormatSuggestion({
            target_type: 'format',
            suggested_value: 'text/csv',
            suggested_label: 'CSV file',
        });

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('File format')).toBeInTheDocument();
        expect(screen.getByText('Suggested format: CSV file (text/csv)')).toBeInTheDocument();
        expect(screen.queryByText('ZIP Archive')).not.toBeInTheDocument();
    });

    it('renders source, confidence, friendly probe method and evidence metadata', () => {
        const suggestion = makeSizeFormatSuggestion({
            suggested_value: '2 MB',
            suggested_label: 'SIZE: 2 MB',
            target_type: 'size',
            metadata: {
                source_url: 'https://datapub.gfz.de/download/example/',
                probe_method: 'DIRECTORY_LISTING',
                confidence: 'high',
                evidence: {
                    parsed_file_count: 2,
                    total_file_count: 3,
                },
            },
        });

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('File size')).toBeInTheDocument();
        expect(screen.getByText('Suggested size: 2 MB')).toBeInTheDocument();
        expect(screen.getByText('High confidence')).toBeInTheDocument();
        expect(screen.getByText('Calculated from download page')).toBeInTheDocument();
        expect(screen.queryByText('DIRECTORY_LISTING')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open source' })).toHaveAttribute('href', 'https://datapub.gfz.de/download/example/');
        expect(screen.getByText('Files counted: 2 of 3')).toBeInTheDocument();
    });

    it('renders filename-extension evidence without exposing internal probe constants', () => {
        const suggestion = makeSizeFormatSuggestion({
            suggested_value: 'application/pdf',
            suggested_label: 'FORMAT: application/pdf',
            target_type: 'format',
            metadata: {
                source_url: 'https://datapub.gfz.de/download/example.pdf',
                probe_method: 'FILENAME_EXTENSION',
                confidence: 'medium',
                evidence: {
                    filename: 'example.pdf',
                },
            },
        });

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Suggested format: PDF document (application/pdf)')).toBeInTheDocument();
        expect(screen.getByText('Medium confidence')).toBeInTheDocument();
        expect(screen.getByText('Detected from file name')).toBeInTheDocument();
        expect(screen.queryByText('FILENAME_EXTENSION')).not.toBeInTheDocument();
        expect(screen.getByText('Detected from file: example.pdf')).toBeInTheDocument();
    });

    it('posts accept and decline requests through the size-format route prefix', async () => {
        const suggestion = makeSizeFormatSuggestion({ id: 77 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({ data: { success: true, message: 'Format applied.' } })
            .mockResolvedValueOnce({ data: {} });

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/size-format/77/accept');
        });

        await user.click(screen.getByRole('button', { name: 'Decline' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/size-format/77/decline');
        });
    });
});

describe('RorSuggestionCard – ROR link', () => {
    it('renders the suggested ROR ID as a clickable link', () => {
        const suggestion = makeRorSuggestion();

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: 'https://ror.org/04t3en479' });
        expect(link).toBeInTheDocument();
    });

    it('links to the correct ROR profile URL', () => {
        const rorId = 'https://ror.org/02nr0ka47';
        const suggestion = makeRorSuggestion({ suggested_ror_id: rorId });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: rorId });
        expect(link).toHaveAttribute('href', rorId);
    });

    it('opens the ROR profile in a new tab', () => {
        const suggestion = makeRorSuggestion();

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: 'https://ror.org/04t3en479' });
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('includes noopener noreferrer for security', () => {
        const suggestion = makeRorSuggestion();

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: 'https://ror.org/04t3en479' });
        const rel = link.getAttribute('rel') ?? '';
        expect(rel).toContain('noopener');
        expect(rel).toContain('noreferrer');
    });

    it('renders multiple ROR suggestions with unique links', () => {
        const suggestions = [
            makeRorSuggestion({ id: 1, suggested_ror_id: 'https://ror.org/04t3en479', entity_name: 'GFZ Potsdam' }),
            makeRorSuggestion({ id: 2, suggested_ror_id: 'https://ror.org/02nr0ka47', entity_name: 'AWI Bremerhaven' }),
        ];

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated(suggestions) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        const link1 = screen.getByRole('link', { name: 'https://ror.org/04t3en479' });
        const link2 = screen.getByRole('link', { name: 'https://ror.org/02nr0ka47' });

        expect(link1).toHaveAttribute('href', 'https://ror.org/04t3en479');
        expect(link2).toHaveAttribute('href', 'https://ror.org/02nr0ka47');
    });

    it('renders plain text instead of a link for a javascript: URL', () => {
        const suggestion = makeRorSuggestion({ suggested_ror_id: 'javascript:alert(1)' });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: 'javascript:alert(1)' })).not.toBeInTheDocument();
        expect(screen.getByText(/javascript:alert/)).toBeInTheDocument();
    });

    it('renders plain text instead of a link for a non-ror.org host', () => {
        const suggestion = makeRorSuggestion({ suggested_ror_id: 'https://evil.com/04t3en479' });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: 'https://evil.com/04t3en479' })).not.toBeInTheDocument();
        expect(screen.getByText(/evil\.com/)).toBeInTheDocument();
    });

    it('renders plain text instead of a link for an http (non-https) ROR URL', () => {
        const suggestion = makeRorSuggestion({ suggested_ror_id: 'http://ror.org/04t3en479' });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: 'http://ror.org/04t3en479' })).not.toBeInTheDocument();
        expect(screen.getByText(/ror\.org/)).toBeInTheDocument();
    });

    it('renders plain text for a ror.org URL with a non-identifier path', () => {
        const suggestion = makeRorSuggestion({ suggested_ror_id: 'https://ror.org/search' });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        expect(screen.queryByRole('link', { name: 'https://ror.org/search' })).not.toBeInTheDocument();
        expect(screen.getByText(/ror\.org\/search/)).toBeInTheDocument();
    });
});

describe('DOI link – Landing page navigation', () => {
    it('renders the DOI as a clickable link', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/test.2024.001' });
        expect(link).toBeInTheDocument();
    });

    it('displays the DOI link as underlined (clearly recognizable as a link)', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/test.2024.001' });
        expect(link).toHaveClass('underline');
    });

    it('links to the correct landing page URL using the legacy route', () => {
        const suggestion = makeOrcidSuggestion({ resource_id: 42 });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/test.2024.001' });
        expect(link).toHaveAttribute('href', '/datasets/42');
    });

    it('opens the landing page in a new browser tab', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/test.2024.001' });
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('includes noopener noreferrer for security', () => {
        const suggestion = makeOrcidSuggestion();

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/test.2024.001' });
        const rel = link.getAttribute('rel') ?? '';
        expect(rel).toContain('noopener');
        expect(rel).toContain('noreferrer');
    });

    it('renders multiple DOI links with correct unique URLs for each resource', () => {
        const suggestions = [
            makeOrcidSuggestion({ id: 1, resource_id: 10, resource_doi: '10.5880/gfz.1' }),
            makeOrcidSuggestion({ id: 2, resource_id: 20, resource_doi: '10.5880/gfz.2' }),
        ];

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated(suggestions) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link1 = screen.getByRole('link', { name: '10.5880/gfz.1' });
        const link2 = screen.getByRole('link', { name: '10.5880/gfz.2' });

        expect(link1).toHaveAttribute('href', '/datasets/10');
        expect(link2).toHaveAttribute('href', '/datasets/20');
    });

    it('displays fallback text when DOI is missing', () => {
        const suggestion = makeOrcidSuggestion({ resource_doi: '' });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        expect(screen.getByText('Dataset')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: '10.5880/test.2024.001' })).not.toBeInTheDocument();
    });
});
