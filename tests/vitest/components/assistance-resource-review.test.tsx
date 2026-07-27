import { router } from '@inertiajs/react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import type { AnchorHTMLAttributes } from 'react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ResourceReview } from '@/components/assistance/resource-review';
import type { AssistanceResourceGroup, AssistantManifest, BaseSuggestionItem, PaginatedData, SuggestionAcceptanceInput } from '@/types/assistance';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} data-inertia-link="true" {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn(), put: vi.fn() },
}));
vi.mock('axios', () => ({ default: { post: vi.fn(), isAxiosError: vi.fn(() => false) } }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() } }));

const manifest: AssistantManifest = {
    id: 'test-assistant',
    name: 'Test assistant',
    description: 'Reviews test metadata.',
    icon: 'test',
    version: '1.0.0',
    routePrefix: 'test',
    sortOrder: 1,
    statusLabels: {},
    emptyState: { title: 'Nothing pending', description: 'Everything is reviewed.' },
    cardComponent: null,
};

function suggestion(id: number, label: string, options: { target?: string | null; canAccept?: boolean } = {}): BaseSuggestionItem {
    return {
        id,
        assistant_id: manifest.id,
        resource_id: 10,
        resource_doi: '10.1234/test',
        resource_title: 'Test resource',
        discovered_at: '2026-07-20T10:00:00+00:00',
        suggested_label: label,
        review: {
            assistant_id: manifest.id,
            assistant_name: manifest.name,
            route_prefix: manifest.routePrefix,
            can_accept: options.canAccept ?? true,
            can_decline: true,
            exclusive_target_key: options.target ?? null,
            label,
        },
    };
}

function page(group: AssistanceResourceGroup): PaginatedData<AssistanceResourceGroup> {
    return { data: [group], current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, links: [] };
}

function renderReview(
    items: BaseSuggestionItem[],
    acceptanceInputs: Record<string, SuggestionAcceptanceInput> = {},
    options: { collapsedAssistantIds?: string[] | null; total?: number; lastPage?: number } = {},
) {
    const group: AssistanceResourceGroup = {
        resource_id: 10,
        resource_doi: '10.1234/test',
        resource_title: 'Test resource',
        suggestion_count: items.length,
        suggestions: items,
    };
    const data = page(group);
    data.total = options.total ?? data.total;
    data.last_page = options.lastPage ?? data.last_page;
    const onReload = vi.fn();
    const onRorFollowUps = vi.fn();

    const rendered = render(
        <ResourceReview
            allAssistantResources={data}
            sections={{ [manifest.id]: data }}
            manifests={[manifest]}
            assistanceCollapsedAssistantIds={options.collapsedAssistantIds}
            checking={{ [manifest.id]: false }}
            onCheck={vi.fn()}
            onReload={onReload}
            onRorFollowUps={onRorFollowUps}
            renderSuggestion={(_manifest, item) => <p>{String(item.suggested_label)}</p>}
            acceptanceInputs={acceptanceInputs}
        />,
    );

    return { onReload, onRorFollowUps, unmount: rendered.unmount };
}

function memoryStorage(): Storage {
    const values = new Map<string, string>();

    return {
        get length() {
            return values.size;
        },
        clear: () => values.clear(),
        getItem: (key) => values.get(key) ?? null,
        key: (index) => [...values.keys()][index] ?? null,
        removeItem: (key) => {
            values.delete(key);
        },
        setItem: (key, value) => {
            values.set(key, value);
        },
    };
}

beforeEach(() => {
    vi.clearAllMocks();

    if (window.localStorage === undefined) {
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            value: memoryStorage(),
        });
    }

    window.localStorage.clear();
});

describe('resource-oriented assistance review', () => {
    it('does not misclassify a legacy suggestion with an assistant-specific suggestions field', () => {
        const item: BaseSuggestionItem = {
            ...suggestion(1, 'Legacy candidate'),
            suggestions: ['assistant-specific evidence'],
        };
        const legacyPage: PaginatedData<BaseSuggestionItem> = {
            data: [item],
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
            from: 1,
            to: 1,
            links: [],
        };

        render(
            <ResourceReview
                sections={{ [manifest.id]: legacyPage }}
                manifests={[manifest]}
                checking={{ [manifest.id]: false }}
                onCheck={vi.fn()}
                onReload={vi.fn()}
                onRorFollowUps={vi.fn()}
                renderSuggestion={(_manifest, suggestionItem) => <p>{String(suggestionItem.suggested_label)}</p>}
            />,
        );

        expect(screen.getByText('Legacy candidate')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Select Test assistant: Legacy candidate' })).toBeInTheDocument();
    });

    it('uses Inertia navigation for the internal resource editor link', () => {
        renderReview([suggestion(1, 'Normal candidate')]);

        const link = screen.getByRole('link', { name: '10.1234/test' });

        expect(link).toHaveAttribute('href', '/editor?resourceId=10');
        expect(link).toHaveAttribute('data-inertia-link', 'true');
        expect(link).toHaveClass('focus-visible:ring-2');
    });

    it('defaults to all assistants and persists the selected view', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'Normal candidate')]);

        expect(screen.getByRole('tab', { name: 'All assistants' })).toHaveAttribute('data-state', 'active');

        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        expect(window.localStorage.getItem('assistance.review-view')).toBe('assistant');
        expect(screen.getByRole('button', { name: 'Test assistant, 1 resource with suggestions' })).toBeInTheDocument();
    });

    it('restores a valid preference and ignores invalid stored values', () => {
        window.localStorage.setItem('assistance.review-view', 'assistant');
        const first = renderReview([suggestion(1, 'Stored view candidate')]);
        expect(screen.getByRole('tab', { name: 'By assistant' })).toHaveAttribute('data-state', 'active');

        first.unmount();
        window.localStorage.setItem('assistance.review-view', 'invalid');
        renderReview([suggestion(1, 'Invalid stored view candidate')]);
        expect(screen.getByRole('tab', { name: 'All assistants' })).toHaveAttribute('data-state', 'active');
    });

    it('opens every Assistant by default and uses resource terminology for the total and pagination', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'First candidate'), suggestion(2, 'Second candidate')], {}, { total: 7, lastPage: 2 });

        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        const trigger = screen.getByRole('button', { name: 'Test assistant, 7 resources with suggestions' });
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText('7 resources with suggestions')).toBeInTheDocument();
        expect(screen.getByText('Showing 1–1 of 7 resources')).toBeInTheDocument();
        expect(screen.queryByText(/2 pending suggestion/)).not.toBeInTheDocument();
        expect(screen.getByText(manifest.description)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: `Check ${manifest.name}` })).toBeInTheDocument();
    });

    it('restores a collapsed Assistant with only its name, resource total, and trigger visible', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'Stored collapsed candidate')], {}, { collapsedAssistantIds: [manifest.id] });

        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        const trigger = screen.getByRole('button', { name: 'Test assistant, 1 resource with suggestions' });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByText(manifest.name)).toBeInTheDocument();
        expect(screen.getByText('1 resource with suggestions')).toBeInTheDocument();
        expect(screen.queryByText(manifest.description)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: `Check ${manifest.name}` })).not.toBeInTheDocument();
        expect(screen.queryByTestId(`assistance-results-${manifest.id}`)).not.toBeInTheDocument();
    });

    it('persists an individual Assistant toggle after the preference debounce', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'Toggle candidate')]);
        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        await user.click(screen.getByRole('button', { name: 'Test assistant, 1 resource with suggestions' }));

        await waitFor(() =>
            expect(vi.mocked(router.put)).toHaveBeenCalledWith(
                '/settings/assistance-accordion',
                { collapsed_assistant_ids: [manifest.id] },
                expect.objectContaining({
                    preserveScroll: true,
                    preserveState: true,
                    only: ['assistanceCollapsedAssistantIds'],
                }),
            ),
        );
    });

    it('collapses and expands all Assistants immediately with matching disabled states', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'Bulk toggle candidate')]);
        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        const collapseAll = screen.getByRole('button', { name: 'Collapse all' });
        const expandAll = screen.getByRole('button', { name: 'Expand all' });
        expect(collapseAll).toBeEnabled();
        expect(expandAll).toBeDisabled();

        await user.click(collapseAll);

        expect(vi.mocked(router.put)).toHaveBeenLastCalledWith(
            '/settings/assistance-accordion',
            { collapsed_assistant_ids: [manifest.id] },
            expect.any(Object),
        );
        expect(collapseAll).toBeDisabled();
        expect(expandAll).toBeEnabled();

        await user.click(expandAll);

        expect(vi.mocked(router.put)).toHaveBeenLastCalledWith('/settings/assistance-accordion', { collapsed_assistant_ids: [] }, expect.any(Object));
        expect(collapseAll).toBeEnabled();
        expect(expandAll).toBeDisabled();
    });

    it('reports profile persistence failures without blocking the local accordion state', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'Failed persistence candidate')]);
        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        await user.click(screen.getByRole('button', { name: 'Collapse all' }));
        const options = vi.mocked(router.put).mock.calls.at(-1)?.[2];
        options?.onError?.({});

        expect(toast.error).toHaveBeenCalledWith('Failed to save the assistant display preference.');
        expect(screen.getByRole('button', { name: 'Test assistant, 1 resource with suggestions' })).toHaveAttribute('aria-expanded', 'false');
    });

    it('keeps newly registered Assistants expanded when they are absent from the stored collapsed IDs', async () => {
        const user = userEvent.setup();
        const newManifest: AssistantManifest = {
            ...manifest,
            id: 'new-assistant',
            name: 'New assistant',
            description: 'Reviews newly supported metadata.',
            routePrefix: 'new-assistant',
            emptyState: { title: 'No new work', description: 'The new assistant has nothing pending.' },
        };
        const existingGroup: AssistanceResourceGroup = {
            resource_id: 10,
            resource_doi: '10.1234/test',
            resource_title: 'Test resource',
            suggestion_count: 1,
            suggestions: [suggestion(1, 'Existing candidate')],
        };
        const existingPage = page(existingGroup);
        const newPage: PaginatedData<AssistanceResourceGroup> = {
            data: [],
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 0,
            from: null,
            to: null,
            links: [],
        };

        render(
            <ResourceReview
                allAssistantResources={existingPage}
                sections={{ [manifest.id]: existingPage, [newManifest.id]: newPage }}
                manifests={[manifest, newManifest]}
                assistanceCollapsedAssistantIds={[manifest.id]}
                checking={{ [manifest.id]: false, [newManifest.id]: false }}
                onCheck={vi.fn()}
                onReload={vi.fn()}
                onRorFollowUps={vi.fn()}
                renderSuggestion={(_manifest, item) => <p>{String(item.suggested_label)}</p>}
            />,
        );

        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        expect(screen.getByRole('button', { name: 'Test assistant, 1 resource with suggestions' })).toHaveAttribute('aria-expanded', 'false');
        expect(screen.getByRole('button', { name: 'New assistant, 0 resources with suggestions' })).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText(newManifest.description)).toBeInTheDocument();
        expect(screen.getByText(newManifest.emptyState.title)).toBeInTheDocument();
    });

    it('selects only compatible single candidates and blocks accepting decline-only hints', async () => {
        const user = userEvent.setup();
        renderReview([
            suggestion(1, 'Normal candidate'),
            suggestion(2, 'Exclusive A', { target: 'person:5' }),
            suggestion(3, 'Exclusive B', { target: 'person:5' }),
            suggestion(4, 'Review hint', { canAccept: false }),
        ]);

        expect(screen.getAllByRole('button', { name: 'Accept' })).toHaveLength(1);
        expect(screen.getAllByRole('button', { name: 'Decline' })).toHaveLength(1);

        await user.click(screen.getByRole('button', { name: 'Select all compatible' }));

        expect(screen.getByRole('checkbox', { name: 'Select Test assistant: Normal candidate' })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Select Test assistant: Exclusive A' })).not.toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Select Test assistant: Exclusive B' })).not.toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Select Test assistant: Review hint' })).not.toBeChecked();
        expect(screen.getByRole('button', { name: 'Accept' })).toBeEnabled();

        await user.click(screen.getByRole('checkbox', { name: 'Select Test assistant: Review hint' }));

        expect(screen.getByRole('button', { name: 'Accept' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Decline' })).toBeEnabled();
        expect(screen.getByText('The selection contains a hint that can only be declined.')).toBeInTheDocument();
    });

    it('allows declining but not accepting multiple alternatives for one target', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(2, 'Exclusive A', { target: 'person:5' }), suggestion(3, 'Exclusive B', { target: 'person:5' })]);

        await user.click(screen.getByRole('checkbox', { name: 'Select Test assistant: Exclusive A' }));
        await user.click(screen.getByRole('checkbox', { name: 'Select Test assistant: Exclusive B' }));

        expect(screen.getByRole('button', { name: 'Accept' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Decline' })).toBeEnabled();
        expect(screen.getByText(/Select at most one ORCID or ROR alternative/)).toBeInTheDocument();
        const alternativeGroup = screen.getByRole('group', { name: 'Either/or alternatives for Exclusive A' });
        expect(alternativeGroup).toHaveTextContent('Either/or — select at most one to accept');
        expect(alternativeGroup.querySelector('.border-dashed')).toBeInTheDocument();
    });

    it('shares a selection between all-assistant and per-assistant views', async () => {
        const user = userEvent.setup();
        renderReview([suggestion(1, 'Shared candidate')]);

        await user.click(screen.getByRole('checkbox', { name: 'Select Test assistant: Shared candidate' }));
        await user.click(screen.getByRole('tab', { name: 'By assistant' }));

        expect(screen.getByRole('checkbox', { name: 'Select Test assistant: Shared candidate' })).toBeChecked();
    });

    it('posts one resource batch and exposes complete results and follow-ups', async () => {
        const user = userEvent.setup();
        const followUp = {
            available: true,
            count: 2,
            bulk_token: 'bulk-token',
            creator_name: 'Doe, Jane',
            affiliation: 'GFZ Potsdam',
            suggested_ror_id: 'https://ror.org/04t3en479',
        };
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                success: true,
                action: 'accept',
                resource_id: 10,
                resource_label: '10.1234/test',
                processed_count: 1,
                success_count: 1,
                failure_count: 0,
                message: '1 suggestion(s) accepted.',
                synced_dois: ['10.1234/test'],
                follow_ups: [followUp],
                results: [
                    {
                        assistant_id: manifest.id,
                        assistant_name: manifest.name,
                        suggestion_id: 1,
                        label: 'Normal candidate',
                        success: true,
                        message: 'Accepted.',
                        synced_dois: ['10.1234/test'],
                    },
                ],
            },
        });
        const { onReload, onRorFollowUps } = renderReview([suggestion(1, 'Normal candidate')]);

        await user.click(screen.getByRole('checkbox', { name: 'Select Test assistant: Normal candidate' }));
        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/assistance/suggestions/batch/accept', {
                resource_id: 10,
                suggestions: [{ assistant_id: manifest.id, suggestion_id: 1 }],
            });
            expect(toast.success).toHaveBeenCalledWith('1 suggestion(s) accepted.', {
                description: 'Test assistant: Normal candidate — Accepted.',
            });
            expect(onRorFollowUps).toHaveBeenCalledWith([followUp]);
            expect(onReload).toHaveBeenCalledOnce();
        });
    });

    it('never sends an acceptance override when declining a selection', async () => {
        const user = userEvent.setup();
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                success: true,
                action: 'decline',
                resource_id: 10,
                resource_label: '10.1234/test',
                processed_count: 1,
                success_count: 1,
                failure_count: 0,
                message: '1 suggestion(s) declined.',
                synced_dois: [],
                follow_ups: [],
                results: [
                    {
                        assistant_id: manifest.id,
                        assistant_name: manifest.name,
                        suggestion_id: 1,
                        label: 'Normal candidate',
                        success: true,
                        message: 'Declined.',
                        synced_dois: [],
                    },
                ],
            },
        });
        renderReview([suggestion(1, 'Normal candidate')], {
            [`${manifest.id}:1`]: { relation_type_id: 42 },
        });

        await user.click(screen.getByRole('checkbox', { name: 'Select Test assistant: Normal candidate' }));
        await user.click(screen.getByRole('button', { name: 'Decline' }));

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/assistance/suggestions/batch/decline', {
                resource_id: 10,
                suggestions: [{ assistant_id: manifest.id, suggestion_id: 1 }],
            }),
        );
    });
});
