import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ResourceRelationHighlightSections } from '@/pages/LandingPages/components/ResourceRelationHighlightSections';
import type { ResourceRelatedWorkGroup } from '@/pages/LandingPages/lib/resource-related-work';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem } from '@/types/landing-page';

const { mockToastSuccess, mockToastError } = vi.hoisted(() => ({
    mockToastSuccess: vi.fn(),
    mockToastError: vi.fn(),
}));

vi.mock('sonner', () => ({
    toast: {
        success: mockToastSuccess,
        error: mockToastError,
    },
}));

const writeText = vi.fn();

function group(
    relatedIdentifiers: LandingPageRelatedIdentifier[] = [],
    relatedItems: LandingPageRelatedItem[] = [],
): ResourceRelatedWorkGroup {
    return { relatedIdentifiers, relatedItems };
}

function relatedIdentifier(overrides: Partial<LandingPageRelatedIdentifier> = {}): LandingPageRelatedIdentifier {
    return {
        id: 1,
        identifier: '10.5880/key-publication',
        identifier_type: 'DOI',
        relation_type: 'IsSupplementTo',
        citation_label: 'Key publication citation',
        ...overrides,
    };
}

function relatedItem(overrides: Partial<LandingPageRelatedItem> = {}): LandingPageRelatedItem {
    return {
        id: 10,
        related_item_type: 'JournalArticle',
        relation_type: 'Is Documented By',
        relation_type_slug: 'IsDocumentedBy',
        publication_year: 2025,
        volume: '4',
        issue: '2',
        number: null,
        number_type: null,
        first_page: '10',
        last_page: '20',
        publisher: 'GFZ Data Services',
        edition: null,
        identifier: '10.5880/dataset-description',
        identifier_type: 'DOI',
        related_metadata_scheme: null,
        scheme_uri: null,
        scheme_type: null,
        position: 1,
        titles: [{ id: 1, title: 'Dataset description article', title_type: 'MainTitle', language: 'en' }],
        creators: [],
        contributors: [],
        ...overrides,
    };
}

describe('ResourceRelationHighlightSections', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        writeText.mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
    });

    it('returns null when neither group has renderable content', () => {
        const { container } = render(
            <ResourceRelationHighlightSections
                keyPublications={group([relatedIdentifier({ identifier: '', citation_label: 'Unsafe' })])}
                datasetDescriptions={group()}
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders both cards in the fixed semantic order', () => {
        render(
            <ResourceRelationHighlightSections
                keyPublications={group([relatedIdentifier()])}
                datasetDescriptions={group([], [relatedItem()])}
            />,
        );

        const keyPublication = screen.getByTestId('key-publication-section');
        const datasetDescription = screen.getByTestId('dataset-description-section');

        expect(within(keyPublication).getByRole('heading', { level: 2, name: 'Key Publication' })).toBeInTheDocument();
        expect(within(datasetDescription).getByRole('heading', { level: 2, name: 'Dataset Description' })).toBeInTheDocument();
        expect(keyPublication.compareDocumentPosition(datasetDescription) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Key Publication' })).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Dataset Description' })).toBeInTheDocument();
    });

    it('renders identifier label fallbacks and safe external links in source order', () => {
        render(
            <ResourceRelationHighlightSections
                keyPublications={
                    group([
                        relatedIdentifier({ id: 1, citation_label: 'First citation' }),
                        relatedIdentifier({
                            id: 2,
                            identifier: 'https://example.com/publication',
                            identifier_type: 'URL',
                            citation_label: null,
                            related_title: 'Second related title',
                        }),
                        relatedIdentifier({ id: 3, identifier: '10.5880/fallback', citation_label: null, related_title: null }),
                    ])
                }
                datasetDescriptions={group()}
            />,
        );

        expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual([
            'First citation',
            'Second related title',
            'DOI: 10.5880/fallback',
        ]);
        expect(screen.getByRole('link', { name: 'First citation' })).toHaveAttribute('href', 'https://doi.org/10.5880/key-publication');
        expect(screen.getByRole('link', { name: 'Second related title' })).toHaveAttribute('href', 'https://example.com/publication');
    });

    it('sorts inline metadata by position and keeps incomplete items visible', () => {
        render(
            <ResourceRelationHighlightSections
                keyPublications={group()}
                datasetDescriptions={
                    group([], [
                        relatedItem({
                            id: 11,
                            position: 2,
                            identifier: null,
                            identifier_type: null,
                            titles: [{ id: 11, title: 'Forthcoming description', title_type: 'MainTitle', language: 'en' }],
                        }),
                        relatedItem({ id: 12, position: 1, titles: [{ id: 12, title: 'Published description', title_type: 'MainTitle', language: 'en' }] }),
                    ])
                }
            />,
        );

        const section = screen.getByTestId('dataset-description-section');
        expect(within(section).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
            expect.stringContaining('Published description'),
            expect.stringContaining('Forthcoming description'),
        ]);
        expect(within(section).getAllByText('Inline metadata')).toHaveLength(2);
        expect(within(section).getByText('Identifier not yet available')).toBeInTheDocument();
        expect(within(section).queryByRole('link', { name: /Forthcoming description/ })).not.toBeInTheDocument();
    });

    it('copies a complete citation and keeps repository-curation styling', async () => {
        const user = userEvent.setup();
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        render(
            <ResourceRelationHighlightSections
                keyPublications={
                    group([
                        relatedIdentifier({
                            id: 42,
                            citation_label: '  Complete citation  ',
                            source: 'relation_suggestion_assistant',
                            is_repository_curation: true,
                        }),
                    ])
                }
                datasetDescriptions={group()}
            />,
        );

        expect(screen.getByTestId('related-work-entry-42')).toHaveClass('bg-cyan-50/70');
        await user.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));

        expect(writeText).toHaveBeenCalledWith('Complete citation');
        expect(mockToastSuccess).toHaveBeenCalledWith('Citation copied to clipboard');
        expect(screen.getByRole('status')).toHaveTextContent('Citation copied to clipboard');
    });

    it('reports clipboard failures without leaving copied state behind', async () => {
        writeText.mockRejectedValueOnce(new Error('Denied'));
        const user = userEvent.setup();
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        render(
            <ResourceRelationHighlightSections keyPublications={group([relatedIdentifier()])} datasetDescriptions={group()} />,
        );

        await user.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));

        expect(mockToastError).toHaveBeenCalledWith('Failed to copy citation');
        expect(screen.getByRole('status')).toHaveTextContent('');
    });
});
