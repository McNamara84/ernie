import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import type { AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { FundingAwardUriCard } from '@/components/assistance/funding-award-uri-card';
import { ResourceReview } from '@/components/assistance/resource-review';
import type { AwardUriMetadata } from '@/lib/funding-award-uri';
import { awardUriInput, awardUriMetadata } from '@/lib/funding-award-uri';
import type { AssistantManifest, BaseSuggestionItem } from '@/types/assistance';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props}>{children}</a>,
    router: { get: vi.fn(), put: vi.fn() },
}));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), isAxiosError: vi.fn(() => false) } }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() } }));

const manifest: AssistantManifest = {
    id: 'funding-award-uri-enrichment',
    name: 'Award URI enrichment',
    description: 'Find missing award URIs.',
    icon: 'BadgeCheck',
    version: '1.0.0',
    routePrefix: 'funding-award-uris',
    sortOrder: 46,
    statusLabels: {},
    emptyState: { title: 'No suggestions', description: '' },
    cardComponent: null,
};

function suggestion(): BaseSuggestionItem {
    const metadata: AwardUriMetadata = {
        current: {
            funder_name: 'Wellcome',
            funder_identifier: '10.13039/100010269',
            award_number: '218300',
            award_title: 'Research award',
            award_uri: null,
        },
        proposed: { award_uri: 'https://doi.org/10.35802/218300' },
        confidence: { level: 'high', evidence: ['exact_award_number'] },
        ambiguity: { status: 'none', candidate_count: 1 },
        review_fingerprint: 'a'.repeat(64),
        review_status: null,
        expires_at: '2026-11-01T00:00:00Z',
        provenance: [
            {
                provider: 'crossref',
                record_id: '10.35802/218300',
                award_number: '218300',
                source_url: 'https://api.crossref.org/works/10.35802%2F218300',
                fetched_at: '2026-10-26T00:00:00Z',
                titles: ['Research award'],
                funder_ids: ['10.13039/100010269'],
                programmes: [],
                attribution: 'Source: Crossref.',
                license_url: 'https://www.crossref.org/documentation/retrieve-metadata/',
            },
        ],
    };
    return {
        id: 1,
        assistant_id: manifest.id,
        resource_id: 10,
        resource_doi: '',
        resource_title: 'Test resource',
        discovered_at: '2026-10-26',
        metadata,
        review: {
            assistant_id: manifest.id,
            assistant_name: manifest.name,
            route_prefix: manifest.routePrefix,
            can_accept: true,
            can_decline: true,
            exclusive_target_key: null,
            label: 'Research award',
        },
    };
}

function renderReview(item: BaseSuggestionItem) {
    const group = { resource_id: 10, resource_doi: '', resource_title: 'Test resource', suggestion_count: 1, suggestions: [item] };
    const page = { data: [group], current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1, links: [] };
    return render(
        <ResourceReview
            allAssistantResources={page}
            sections={{ [manifest.id]: page }}
            manifests={[manifest]}
            checking={{}}
            onCheck={vi.fn()}
            onReload={vi.fn()}
            onRorFollowUps={vi.fn()}
            renderSuggestion={(_manifest, item) => <FundingAwardUriCard suggestion={item} />}
        />,
    );
}

beforeEach(() => vi.clearAllMocks());

describe('award URI review', () => {
    it('shows the current and proposed values, source evidence and permanent review notice', async () => {
        render(<FundingAwardUriCard suggestion={suggestion()} />);
        expect(screen.getByText('Current award URI')).toBeInTheDocument();
        expect(screen.getByText('Empty')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'https://doi.org/10.35802/218300' })).toHaveAttribute('rel', 'noopener noreferrer');
        await userEvent.click(screen.getByText('Evidence and provenance'));
        expect(screen.getByRole('link', { name: 'View source metadata' })).toHaveAttribute(
            'href',
            'https://api.crossref.org/works/10.35802%2F218300',
        );
        expect(screen.getByText(/Your decision, the source evidence/)).toBeInTheDocument();
    });

    it('does not render unsafe source or proposed URI links', () => {
        const item = suggestion();
        const metadata = item.metadata as AwardUriMetadata;
        metadata.proposed.award_uri = 'javascript:alert(1)';
        metadata.provenance[0].source_url = 'javascript:alert(1)';
        metadata.provenance[0].license_url = 'data:text/html,unsafe';
        render(<FundingAwardUriCard suggestion={item} />);
        expect(screen.queryByRole('link', { name: 'javascript:alert(1)' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'View source metadata' })).not.toBeInTheDocument();
    });

    it('blocks an incomplete preview and emits no review fingerprint', () => {
        const item = { ...suggestion(), metadata: { provenance: [] } };
        expect(awardUriMetadata(item)).toBeNull();
        expect(awardUriInput(item)).toEqual({});
        renderReview(item);
        expect(screen.getByRole('alert')).toHaveTextContent('Award evidence is incomplete');
        expect(screen.getByRole('checkbox', { name: 'Select Award URI enrichment: Research award' })).toBeDisabled();
    });

    it('shows why an outdated preview cannot be accepted', () => {
        const item = suggestion();
        const metadata = item.metadata as AwardUriMetadata;
        metadata.review_status = 'local_data_changed';
        metadata.live_current = { ...metadata.current, award_uri: 'https://example.org/editor-award', award_title: 'Updated award' };
        render(<FundingAwardUriCard suggestion={item} />);
        expect(screen.getByRole('alert')).toHaveTextContent('local data changed');
        expect(screen.getByText('https://example.org/editor-award')).toBeInTheDocument();
        expect(screen.getByText('Updated award')).toBeInTheDocument();
        expect(screen.queryByText('Empty')).not.toBeInTheDocument();
    });

    it('labels a deleted funding reference as historical rather than current', () => {
        const item = suggestion();
        const metadata = item.metadata as AwardUriMetadata;
        metadata.review_status = 'target_missing';
        metadata.live_current = null;
        render(<FundingAwardUriCard suggestion={item} />);
        expect(screen.getByText('Award URI at discovery')).toBeInTheDocument();
        expect(screen.queryByText('Current award URI')).not.toBeInTheDocument();
    });

    it.each(['accept', 'decline'])('sends the exact preview fingerprint for a batch %s', async (action) => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                success: true,
                action,
                resource_id: 10,
                resource_label: 'Test resource',
                processed_count: 1,
                success_count: 1,
                failure_count: 0,
                message: 'Done',
                synced_dois: [],
                follow_ups: [],
                results: [],
            },
        });
        renderReview(suggestion());
        await userEvent.click(screen.getByRole('button', { name: 'Select all compatible' }));
        await userEvent.click(screen.getByRole('button', { name: action === 'accept' ? 'Accept' : 'Decline' }));
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(
                `/assistance/suggestions/batch/${action}`,
                expect.objectContaining({
                    suggestions: [{ assistant_id: manifest.id, suggestion_id: 1, award_uri_review_fingerprint: 'a'.repeat(64) }],
                }),
            ),
        );
    });
});
