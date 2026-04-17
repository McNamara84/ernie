import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PaginatedData, BaseSuggestionItem, SuggestedOrcidItem, SuggestedRorItem } from '@/types/assistance';

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

// ── Import component under test (after mocks) ───────────────────────

// The card components are not exported individually, so we render the
// full page with minimal props and assert on the rendered output.
// We import the default export (AssistancePage).
import AssistancePage from '@/pages/assistance';

// ── Fixtures ─────────────────────────────────────────────────────────

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
        const suggestion = makeOrcidSuggestion({ suggested_orcid: '0000-0002-9999-0001' });

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link = screen.getByRole('link', { name: '0000-0002-9999-0001' });
        expect(link).toHaveAttribute('href', 'https://orcid.org/0000-0002-9999-0001');
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
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('displays only the ORCID ID as link text (not the full URL)', () => {
        const orcid = '0000-0003-1111-2222';
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
            makeOrcidSuggestion({ id: 1, suggested_orcid: '0000-0001-0000-0001' }),
            makeOrcidSuggestion({ id: 2, suggested_orcid: '0000-0001-0000-0002', person_name: 'John Smith' }),
        ];

        render(
            <AssistancePage
                sections={{ 'orcid-suggestion': paginated(suggestions) }}
                manifests={[makeManifest('orcid-suggestion', 'orcids', 'ORCID Suggestions')]}
            />,
        );

        const link1 = screen.getByRole('link', { name: '0000-0001-0000-0001' });
        const link2 = screen.getByRole('link', { name: '0000-0001-0000-0002' });

        expect(link1).toHaveAttribute('href', 'https://orcid.org/0000-0001-0000-0001');
        expect(link2).toHaveAttribute('href', 'https://orcid.org/0000-0001-0000-0002');
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
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
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
});
