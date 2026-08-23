import { router } from '@inertiajs/react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
    AssistanceRelationTypeOption,
    AssistanceResourceGroup,
    AssistantManifest,
    BaseSuggestionItem,
    PaginatedData,
    SuggestedRelationItem,
} from '@/types/assistance';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
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
    const isAxiosError = vi.fn(() => false);
    return { default: { post, get, isAxiosError }, post, get, isAxiosError };
});

import AssistancePage from '@/pages/assistance';

const mockedAxiosPost = axios.post as Mock;
const mockedRouterReload = router.reload as Mock;

const manifest: AssistantManifest = {
    id: 'relation-suggestion',
    name: 'Relation Suggestions',
    description: 'Relation suggestion test',
    icon: 'Link',
    version: '1.0.0',
    routePrefix: 'relations',
    sortOrder: 10,
    statusLabels: {},
    emptyState: { title: 'No suggestions', description: 'No pending relations.' },
    cardComponent: null,
};

const relationTypes: AssistanceRelationTypeOption[] = [
    { id: 1, name: 'Is supplement to', slug: 'IsSupplementTo', usage_count: 50, is_most_used: true },
    { id: 2, name: 'References', slug: 'References', usage_count: 40, is_most_used: true },
    { id: 3, name: 'Cites', slug: 'Cites', usage_count: 30, is_most_used: true },
    { id: 4, name: 'Documents', slug: 'Documents', usage_count: 20, is_most_used: true },
    { id: 5, name: 'Has part', slug: 'HasPart', usage_count: 10, is_most_used: true },
    { id: 6, name: 'Reviews', slug: 'Reviews', usage_count: 1, is_most_used: false },
];

function relationSuggestion(): SuggestedRelationItem {
    return {
        id: 11,
        resource_id: 10,
        resource_doi: '10.5880/test.2026.011',
        resource_title: 'Test resource',
        identifier: '10.1234/suggested-resource',
        identifier_type: 'DOI',
        identifier_type_name: 'DOI',
        relation_type_id: 1,
        relation_type: 'IsSupplementTo',
        relation_type_name: 'Is supplement to',
        source: 'scholexplorer',
        source_title: 'Suggested related resource',
        source_type: 'Dataset',
        source_publisher: 'GFZ',
        source_publication_date: '2026',
        discovered_at: '2026-07-26T10:00:00+00:00',
    };
}

function legacyPage(suggestion: SuggestedRelationItem): PaginatedData<BaseSuggestionItem> {
    return {
        data: [suggestion as unknown as BaseSuggestionItem],
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 1,
        from: 1,
        to: 1,
        links: [],
    };
}

function groupedPage(suggestion: SuggestedRelationItem): PaginatedData<AssistanceResourceGroup> {
    const item = {
        ...suggestion,
        assistant_id: manifest.id,
        review: {
            assistant_id: manifest.id,
            assistant_name: manifest.name,
            route_prefix: manifest.routePrefix,
            can_accept: true,
            can_decline: true,
            exclusive_target_key: null,
            label: suggestion.identifier,
        },
    } as unknown as BaseSuggestionItem;

    return {
        data: [
            {
                resource_id: suggestion.resource_id,
                resource_doi: suggestion.resource_doi,
                resource_title: suggestion.resource_title,
                suggestion_count: 1,
                suggestions: [item],
            },
        ],
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 1,
        from: 1,
        to: 1,
        links: [],
    };
}

beforeEach(() => {
    vi.clearAllMocks();
});

describe('relation type acceptance input', () => {
    it('sends a changed relation type through the single acceptance endpoint', async () => {
        const user = userEvent.setup();
        const suggestion = relationSuggestion();
        mockedAxiosPost.mockResolvedValueOnce({ data: { success: true, message: 'Accepted.' } });

        render(<AssistancePage sections={{ [manifest.id]: legacyPage(suggestion) }} manifests={[manifest]} relationTypes={relationTypes} />);

        const select = screen.getByRole('combobox', { name: `Relation type for ${suggestion.identifier}` });
        await user.click(select);
        await user.click(screen.getByRole('option', { name: /^References/ }));
        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenCalledWith('/assistance/relations/11/accept', { relation_type_id: 2 });
            expect(mockedRouterReload).toHaveBeenCalledWith({
                only: ['sections', 'datacenterOptions', 'relationTypes', 'pendingAssistanceTotalCount'],
            });
        });
    });

    it('removes the override after changing back to the suggested type', async () => {
        const user = userEvent.setup();
        const suggestion = relationSuggestion();
        mockedAxiosPost.mockResolvedValueOnce({ data: { success: true, message: 'Accepted.' } });

        render(<AssistancePage sections={{ [manifest.id]: legacyPage(suggestion) }} manifests={[manifest]} relationTypes={relationTypes} />);

        const select = screen.getByRole('combobox', { name: `Relation type for ${suggestion.identifier}` });
        await user.click(select);
        await user.click(screen.getByRole('option', { name: /^References/ }));
        await user.click(select);
        await user.click(screen.getByRole('option', { name: /^Is supplement to/ }));
        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => expect(mockedAxiosPost).toHaveBeenCalledWith('/assistance/relations/11/accept'));
    });

    it('shares the override between review tabs and includes it in the resource batch', async () => {
        const user = userEvent.setup();
        const suggestion = relationSuggestion();
        const page = groupedPage(suggestion);
        mockedAxiosPost.mockResolvedValueOnce({
            data: {
                success: true,
                action: 'accept',
                resource_id: suggestion.resource_id,
                resource_label: suggestion.resource_doi,
                processed_count: 1,
                success_count: 1,
                failure_count: 0,
                message: 'Accepted.',
                synced_dois: [],
                follow_ups: [],
                results: [
                    {
                        assistant_id: manifest.id,
                        assistant_name: manifest.name,
                        suggestion_id: suggestion.id,
                        label: suggestion.identifier,
                        success: true,
                        message: 'Accepted.',
                        synced_dois: [],
                    },
                ],
            },
        });

        render(
            <AssistancePage allAssistantResources={page} sections={{ [manifest.id]: page }} manifests={[manifest]} relationTypes={relationTypes} />,
        );

        await user.click(screen.getByRole('combobox', { name: `Relation type for ${suggestion.identifier}` }));
        await user.click(screen.getByRole('option', { name: /^References/ }));
        await user.click(screen.getByRole('tab', { name: 'By assistant' }));
        expect(screen.getByRole('combobox', { name: `Relation type for ${suggestion.identifier}` })).toHaveTextContent('References');
        await user.click(screen.getByRole('checkbox', { name: `Select ${manifest.name}: ${suggestion.identifier}` }));
        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() =>
            expect(mockedAxiosPost).toHaveBeenCalledWith('/assistance/suggestions/batch/accept', {
                resource_id: suggestion.resource_id,
                suggestions: [{ assistant_id: manifest.id, suggestion_id: suggestion.id, relation_type_id: 2 }],
            }),
        );
    });
});
