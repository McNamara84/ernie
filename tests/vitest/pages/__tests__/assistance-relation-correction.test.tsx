import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { RelationTypeCorrectionCard } from '@/components/assistance/relation-type-correction-card';
import { relationCorrectionInput, relationCorrectionMetadata } from '@/lib/relation-type-correction';
import AssistancePage, { completionFeedback } from '@/pages/assistance';
import type { AssistantManifest, BaseSuggestionItem } from '@/types/assistance';

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

const fingerprint = 'a'.repeat(64);
const manifest: AssistantManifest = {
    id: 'relation-type-correction',
    name: 'Relation Type Correction',
    description: 'Review existing relations.',
    icon: 'GitCompareArrows',
    version: '1.0.0',
    routePrefix: 'relation-type-correction',
    sortOrder: 11,
    statusLabels: {},
    emptyState: { title: 'No pending corrections', description: 'All reviewed.' },
    cardComponent: 'relation-type-correction-card',
};
function suggestion(): BaseSuggestionItem {
    return {
        id: 11,
        assistant_id: manifest.id,
        resource_id: 10,
        resource_doi: '10.5880/a',
        resource_title: 'Test resource',
        discovered_at: '2026-10-04',
        suggested_label: 'Is Part Of',
        metadata: {
            current: { identifier: '10.5880/b', relation_type: 'HasPart', relation_type_name: 'Has Part', citation_label: 'Manual citation.' },
            proposed: { id: 2, slug: 'IsPartOf', name: 'Is Part Of' },
            rule: { rationale: 'Explicit reversed structural roles.' },
            confidence: { level: 'high', basis: 'Explicit primary assertion' },
            review_fingerprint: fingerprint,
            evidence: [
                {
                    provider: 'datacite',
                    claimant: '10.5880/b',
                    subject: '10.5880/b',
                    object: '10.5880/a',
                    original_relation: 'HasPart',
                    relation: 'HasPart',
                    source_url: 'https://api.datacite.org/dois/10.5880/b',
                    fetched_at: '2026-10-04',
                    primary: true,
                },
            ],
        },
        review: {
            assistant_id: manifest.id,
            assistant_name: manifest.name,
            route_prefix: manifest.routePrefix,
            can_accept: true,
            can_decline: true,
            exclusive_target_key: `${manifest.id}:related_identifier:1`,
            label: 'Is Part Of',
        },
    };
}
function renderPage(grouped = false) {
    const item = suggestion();
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
    window.localStorage.clear();
    vi.mocked(axios.post).mockResolvedValue({ data: { success: true, message: 'Saved.', results: [], follow_ups: [], failure_count: 0 } });
});

describe('relation correction review', () => {
    it('renders the directed before/after preview, rationale, high confidence and source evidence', async () => {
        const user = userEvent.setup();
        render(<RelationTypeCorrectionCard suggestion={suggestion()} />);
        expect(screen.getByText('Has Part (HasPart)')).toBeInTheDocument();
        expect(screen.getByText('Is Part Of (IsPartOf)')).toBeInTheDocument();
        expect(screen.getByText('Manual citation.')).toBeInTheDocument();
        expect(screen.getByText('High confidence')).toBeInTheDocument();
        expect(screen.getByText(/Read the relationship from this resource/)).toBeInTheDocument();
        await user.click(screen.getByText('Evidence and provenance'));
        expect(screen.getByRole('link', { name: 'View source metadata' })).toHaveAttribute('rel', 'noopener noreferrer');
        expect(screen.getByText(/asserted by 10.5880\/b/)).toBeInTheDocument();
    });
    it('does not render unsafe source links or interpret citation labels as HTML', () => {
        const item = suggestion();
        const metadata = relationCorrectionMetadata(item)!;
        metadata.evidence[0].source_url = 'javascript:alert(1)';
        metadata.current.citation_label = '<script>unsafe</script>';
        render(<RelationTypeCorrectionCard suggestion={item} />);
        expect(screen.queryByRole('link', { name: 'View source metadata', hidden: true })).not.toBeInTheDocument();
        expect(screen.getByText('<script>unsafe</script>')).toBeInTheDocument();
    });
    it('shows an incomplete state and never invents a fingerprint', () => {
        const item = { ...suggestion(), metadata: { review_fingerprint: 'bad' } };
        render(<RelationTypeCorrectionCard suggestion={item} />);
        expect(screen.getByText(/proposal is incomplete/)).toBeInTheDocument();
        expect(relationCorrectionInput(item)).toEqual({});
        expect(relationCorrectionInput({ ...suggestion(), assistant_id: 'other', review: undefined })).toEqual({});
    });
    it.each(['Accept', 'Decline'])('sends the displayed fingerprint for the individual %s action', async (action) => {
        const user = userEvent.setup();
        renderPage();
        await user.click(screen.getByRole('button', { name: action }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(`/assistance/relation-type-correction/11/${action.toLowerCase()}`, {
                relation_type_correction_fingerprint: fingerprint,
            }),
        );
    });
    it.each(['Accept', 'Decline'])('sends the displayed fingerprint for the resource batch %s action', async (action) => {
        const user = userEvent.setup();
        renderPage(true);
        await user.click(screen.getByRole('checkbox', { name: 'Select Relation Type Correction: Is Part Of' }));
        await user.click(screen.getByRole('button', { name: action }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(`/assistance/suggestions/batch/${action.toLowerCase()}`, {
                resource_id: 10,
                suggestions: [{ assistant_id: manifest.id, suggestion_id: 11, relation_type_correction_fingerprint: fingerprint }],
            }),
        );
    });
    it('reports checked identifiers, stale removals and unavailable sources in discovery feedback', () => {
        expect(
            completionFeedback(manifest, {
                status: 'completed',
                newSuggestionsFound: 0,
                details: { checked_identifiers: 4, stale_suggestions_removed: 2, incomplete_or_failed_identifiers: 1 },
            }).message,
        ).toContain('Checked 4 related identifier(s); 2 stale removed; 1 incomplete or failed.');
    });
});
