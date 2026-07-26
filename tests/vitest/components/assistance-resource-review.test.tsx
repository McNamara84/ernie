import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import type { AnchorHTMLAttributes } from 'react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ResourceReview } from '@/components/assistance/resource-review';
import type { AssistanceResourceGroup, AssistantManifest, BaseSuggestionItem, PaginatedData } from '@/types/assistance';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} data-inertia-link="true" {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn() },
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

function renderReview(items: BaseSuggestionItem[]) {
    const group: AssistanceResourceGroup = {
        resource_id: 10,
        resource_doi: '10.1234/test',
        resource_title: 'Test resource',
        suggestion_count: items.length,
        suggestions: items,
    };
    const data = page(group);
    const onReload = vi.fn();
    const onRorFollowUps = vi.fn();

    const rendered = render(
        <ResourceReview
            allAssistantResources={data}
            sections={{ [manifest.id]: data }}
            manifests={[manifest]}
            pendingCounts={{ [manifest.id]: items.length }}
            checking={{ [manifest.id]: false }}
            onCheck={vi.fn()}
            onReload={onReload}
            onRorFollowUps={onRorFollowUps}
            renderSuggestion={(_manifest, item) => <p>{String(item.suggested_label)}</p>}
        />,
    );

    return { onReload, onRorFollowUps, unmount: rendered.unmount };
}

beforeEach(() => {
    vi.clearAllMocks();
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
        expect(screen.getByRole('heading', { name: manifest.name })).toBeInTheDocument();
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
});
