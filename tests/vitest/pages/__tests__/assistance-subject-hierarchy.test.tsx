import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import { type AnchorHTMLAttributes,type ReactNode, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SubjectHierarchyCorrectionCard } from '@/components/assistance/subject-hierarchy-correction-card';
import { isSubjectHierarchyReady, subjectHierarchyMetadata } from '@/lib/subject-hierarchy';
import AssistancePage, { completionFeedback } from '@/pages/assistance';
import type { AssistantManifest, BaseSuggestionItem, SubjectHierarchyMetadata, SuggestionAcceptanceInput } from '@/types/assistance';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    Link: ({ children, href, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: {} }),
    router: { reload: vi.fn(), get: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children?: ReactNode }) => <div>{children}</div> }));
vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn(), isAxiosError: vi.fn(() => false) } }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() } }));

const manifest: AssistantManifest = {
    id: 'subject-hierarchy-correction',
    name: 'Subject Hierarchy Correction',
    description: 'Review subjects.',
    icon: 'Tags',
    version: '1.0.0',
    routePrefix: 'subject-hierarchy-correction',
    sortOrder: 55,
    statusLabels: {},
    emptyState: { title: 'No pending subjects', description: 'All reviewed.' },
    cardComponent: 'subject-hierarchy-correction-card',
};
const fingerprint = 'a'.repeat(64);

function suggestion(existing: string[] = []): BaseSuggestionItem {
    const metadata: SubjectHierarchyMetadata = {
        scheme: 'Platforms',
        broader_id: 'root',
        broader_label: 'Root',
        fingerprint,
        nodes: [
            { id: 'root', label: 'Root', path: 'Root', description: '', children: ['branch', 'beta'], selectable: true },
            { id: 'branch', label: 'Branch', path: 'Root > Branch', description: '', children: ['alpha'], selectable: true },
            { id: 'alpha', label: 'Alpha', path: 'Root > Branch > Alpha', description: 'Alpha definition', children: [], selectable: true },
            { id: 'beta', label: 'Beta', path: 'Root > Beta', description: '', children: [], selectable: true },
        ],
        leaf_ids: ['alpha', 'beta'],
        existing_leaf_ids: existing,
    };
    return {
        id: 11,
        assistant_id: manifest.id,
        resource_id: 10,
        resource_doi: '10.5880/test',
        resource_title: 'Test resource',
        discovered_at: '2026-10-01T10:00:00Z',
        suggested_label: 'Review Root',
        metadata: metadata as unknown as Record<string, unknown>,
        review: {
            assistant_id: manifest.id,
            assistant_name: manifest.name,
            route_prefix: manifest.routePrefix,
            can_accept: true,
            can_decline: true,
            exclusive_target_key: `${manifest.id}:10:Platforms`,
            label: 'Review Root',
        },
    };
}

function CardHarness({ item }: { item: BaseSuggestionItem }) {
    const [input, setInput] = useState<SuggestionAcceptanceInput>({});
    return <SubjectHierarchyCorrectionCard suggestion={item} acceptanceInput={input} onAcceptanceInputChange={setInput} isProcessing={false} />;
}

function renderPage(item: BaseSuggestionItem, grouped = false) {
    const paging = { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, links: [] };
    if (!grouped) return render(<AssistancePage manifests={[manifest]} sections={{ [manifest.id]: { ...paging, data: [item] } }} />);
    const group = {
        ...paging,
        data: [{ resource_id: 10, resource_doi: item.resource_doi, resource_title: item.resource_title, suggestion_count: 1, suggestions: [item] }],
    };
    return render(<AssistancePage manifests={[manifest]} sections={{ [manifest.id]: group }} allAssistantResources={group} />);
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.post).mockResolvedValue({ data: { success: true, message: 'Saved.', results: [], follow_ups: [], failure_count: 0 } });
});

describe('subject hierarchy selection', () => {
    it('renders intermediate nodes as navigation and expands terminal checkboxes on demand', async () => {
        const user = userEvent.setup();
        render(<CardHarness item={suggestion()} />);
        expect(screen.queryByRole('checkbox', { name: /Choose Root > Branch > Alpha/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox', { name: /^Choose Root$/ })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Expand Branch' }));
        await user.click(screen.getByRole('checkbox', { name: 'Choose Root > Branch > Alpha' }));
        expect(screen.getByText('The broader term will be removed.')).toBeInTheDocument();
        expect(screen.getByText('1 subject(s) will be added: Alpha')).toBeInTheDocument();
    });

    it('selects the entire subtree even when search shows only part of it', async () => {
        const user = userEvent.setup();
        render(<CardHarness item={suggestion()} />);
        await user.type(screen.getByRole('textbox', { name: 'Search narrower terms for Root' }), 'Alpha');
        expect(screen.getByRole('checkbox', { name: 'Choose Root > Branch > Alpha' })).toBeVisible();
        expect(screen.queryByRole('checkbox', { name: 'Choose Root > Beta' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Select all 2 narrower terms' }));
        expect(screen.getByText('2 of 2 narrower terms selected.')).toBeInTheDocument();
        expect(screen.getByText('The broader term will be retained.')).toBeInTheDocument();
        expect(screen.getByText('2 subject(s) will be added: Alpha, Beta')).toBeInTheDocument();
    });

    it('protects existing leaf assignments and excludes them from the addition preview', async () => {
        const user = userEvent.setup();
        render(<CardHarness item={suggestion(['beta'])} />);
        expect(screen.getByRole('checkbox', { name: 'Choose Root > Beta (already assigned)' })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Choose Root > Beta (already assigned)' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Select all 2 narrower terms' }));
        expect(screen.getByText('1 subject(s) will be added: Alpha')).toBeInTheDocument();
    });

    it('retains selection across an unchanged reload and resets it for a different semantic case', async () => {
        const user = userEvent.setup();
        const item = suggestion();
        const { rerender } = render(<CardHarness item={item} />);
        await user.click(screen.getByRole('button', { name: 'Select all 2 narrower terms' }));
        rerender(<CardHarness item={{ ...item, discovered_at: '2026-10-02T10:00:00Z' }} />);
        expect(screen.getByText('2 of 2 narrower terms selected.')).toBeInTheDocument();
        rerender(
            <CardHarness
                item={{
                    ...item,
                    metadata: { ...(item.metadata as Record<string, unknown>), fingerprint: 'b'.repeat(64), existing_leaf_ids: ['beta'] },
                }}
            />,
        );
        expect(screen.getByText('1 of 2 narrower terms selected.')).toBeInTheDocument();
    });

    it('renders incomplete cases safely and navigation groups as editor-only hints', () => {
        const item = suggestion();
        const { rerender } = render(<CardHarness item={{ ...item, metadata: {} }} />);
        expect(screen.getByText(/This suggestion is incomplete/)).toBeInTheDocument();
        rerender(<CardHarness item={{ ...item, metadata: { ...(item.metadata as Record<string, unknown>), suggestion_kind: 'hint' } }} />);
        expect(screen.getByRole('link', { name: 'Open resource editor' })).toHaveAttribute('href', '/editor?resourceId=10');
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    });
});

describe('single and batch review actions', () => {
    it('disables the central accept button until a leaf is chosen and sends the confirmed selection', async () => {
        const user = userEvent.setup();
        renderPage(suggestion());
        expect(screen.getByRole('button', { name: 'Accept' })).toBeDisabled();
        await user.click(screen.getByRole('checkbox', { name: 'Choose Root > Beta' }));
        await user.click(screen.getByRole('button', { name: 'Accept' }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/assistance/subject-hierarchy-correction/11/accept', {
                selected_leaf_ids: ['beta'],
                subject_hierarchy_fingerprint: fingerprint,
            }),
        );
    });

    it('requires a nonblank reason for an individual decline and supports cancellation', async () => {
        const user = userEvent.setup();
        renderPage(suggestion());
        await user.click(screen.getByRole('button', { name: 'Decline' }));
        expect(axios.post).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Keep with reason' })).toBeDisabled();
        await user.type(screen.getByRole('textbox', { name: 'Reason for retaining broader terms' }), '   ');
        expect(screen.getByRole('button', { name: 'Keep with reason' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(axios.post).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Decline' }));
        await user.type(screen.getByRole('textbox', { name: 'Reason for retaining broader terms' }), ' Scope describes this resource. ');
        await user.click(screen.getByRole('button', { name: 'Keep with reason' }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/assistance/subject-hierarchy-correction/11/decline', {
                reason: 'Scope describes this resource.',
            }),
        );
    });

    it('does not select hierarchy corrections automatically and shares the selection between review tabs', async () => {
        const user = userEvent.setup();
        renderPage(suggestion(), true);
        await user.click(screen.getByRole('button', { name: /Select all compatible/ }));
        expect(screen.getByRole('checkbox', { name: 'Select Subject Hierarchy Correction: Review Root' })).not.toBeChecked();
        await user.click(screen.getByRole('checkbox', { name: 'Select Subject Hierarchy Correction: Review Root' }));
        expect(screen.getByRole('button', { name: 'Accept' })).toBeDisabled();
        await user.click(screen.getByRole('checkbox', { name: 'Choose Root > Beta' }));
        await user.click(screen.getByRole('tab', { name: 'By assistant' }));
        expect(screen.getByRole('checkbox', { name: 'Choose Root > Beta' })).toBeChecked();
        await user.click(screen.getByRole('button', { name: 'Accept' }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/assistance/suggestions/batch/accept', {
                resource_id: 10,
                suggestions: [
                    { assistant_id: manifest.id, suggestion_id: 11, selected_leaf_ids: ['beta'], subject_hierarchy_fingerprint: fingerprint },
                ],
            }),
        );
    });

    it('asks for the shared reason before submitting a hierarchy batch decline', async () => {
        const user = userEvent.setup();
        renderPage(suggestion(), true);
        await user.click(screen.getByRole('checkbox', { name: 'Select Subject Hierarchy Correction: Review Root' }));
        await user.click(screen.getByRole('button', { name: 'Decline' }));
        expect(axios.post).not.toHaveBeenCalled();
        await user.type(screen.getByRole('textbox', { name: 'Reason for retaining broader terms' }), 'The broader scope is correct.');
        await user.click(screen.getByRole('button', { name: 'Keep with reason' }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/assistance/suggestions/batch/decline', {
                resource_id: 10,
                reason: 'The broader scope is correct.',
                suggestions: [{ assistant_id: manifest.id, suggestion_id: 11 }],
            }),
        );
    });
});

describe('selection validation and check diagnostics', () => {
    it('accepts only a complete valid payload that preserves existing terms', () => {
        const item = suggestion(['beta']);
        const input = { subject_hierarchy_fingerprint: fingerprint, selected_leaf_ids: ['alpha', 'beta'] };
        expect(isSubjectHierarchyReady(item, input)).toBe(true);
        for (const selected_leaf_ids of [[], ['alpha'], ['foreign'], ['beta', 'beta']]) {
            expect(isSubjectHierarchyReady(item, { ...input, selected_leaf_ids })).toBe(false);
        }
        expect(isSubjectHierarchyReady(item, { ...input, subject_hierarchy_fingerprint: 'stale' })).toBe(false);
        expect(subjectHierarchyMetadata({ ...item, assistant_id: 'other', review: undefined })).toBeNull();
    });

    it('includes unavailable vocabularies in completion feedback', () => {
        const result = completionFeedback(manifest, {
            status: 'completed',
            newSuggestionsFound: 0,
            details: { checked_resources: 4, subjects_unresolved: 2, unavailable_vocabularies: 'GEMET: Update the vocabulary.' },
        });
        expect(result.hasResults).toBe(false);
        expect(result.message).toContain('Checked 4 resource(s); 2 unresolved subject(s).');
        expect(result.message).toContain('Unavailable vocabularies: GEMET: Update the vocabulary.');
    });
});
