import { router } from '@inertiajs/react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { toast } from 'sonner';
import type { Mock } from 'vitest';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
    BaseSuggestionItem,
    PaginatedData,
    SuggestedCrossrefFunderRorItem,
    SuggestedDescriptionSegmentationItem,
    SuggestedOrcidItem,
    SuggestedRorItem,
    SuggestedSpdxRightsItem,
    SuggestedSubjectMetadataEnrichmentItem,
} from '@/types/assistance';

// ── Mocks ────────────────────────────────────────────────────────────

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
    const isAxiosError = vi.fn((value: unknown): value is { isAxiosError: true } => {
        return typeof value === 'object' && value !== null && (value as { isAxiosError?: boolean }).isAxiosError === true;
    });

    return { default: { post, get, isAxiosError }, post, get, isAxiosError };
});

const mockedAxiosPost = axios.post as Mock;
const mockedRouterReload = router.reload as Mock;
const mockedToastWarning = toast.warning as Mock;

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
const CROSSREF_FUNDER_ROR_ASSISTANT_ID = 'crossref-funder-ror-suggestion';
const CROSSREF_FUNDER_ROR_ROUTE_PREFIX = 'crossref-funder-rors';
const CROSSREF_FUNDER_ROR_ASSISTANT_NAME = 'Crossref Funder ROR Suggestions';
const SUBJECT_METADATA_ASSISTANT_ID = 'subject-metadata-enrichment';
const SUBJECT_METADATA_ROUTE_PREFIX = 'subject-metadata-enrichment';
const SUBJECT_METADATA_ASSISTANT_NAME = 'Subject Metadata Enrichment';
const DESCRIPTION_SEGMENTATION_ASSISTANT_ID = 'description-segmentation';
const DESCRIPTION_SEGMENTATION_ROUTE_PREFIX = 'description-segmentation';
const DESCRIPTION_SEGMENTATION_ASSISTANT_NAME = 'Description Segmentation Suggestions';
const BULK_TOKEN_MATCH = '00000000-0000-4000-8000-000000000955';
const BULK_TOKEN_SINGULAR = '00000000-0000-4000-8000-000000000958';
const BULK_TOKEN_RETRY = '00000000-0000-4000-8000-000000000957';
const BULK_TOKEN_EXPIRED = '00000000-0000-4000-8000-000000000959';
const BULK_TOKEN_DECLINE = '00000000-0000-4000-8000-000000000956';

beforeEach(() => {
    mockedAxiosPost.mockReset();
    mockedRouterReload.mockReset();
    mockedToastWarning.mockReset();
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

function makeCrossrefFunderRorSuggestion(overrides: Partial<SuggestedCrossrefFunderRorItem> = {}): SuggestedCrossrefFunderRorItem {
    const metadata: SuggestedCrossrefFunderRorItem['metadata'] = {
        current: {
            funding_reference_id: 860,
            resource_id: 50,
            funder_name: 'Deutsche Forschungsgemeinschaft',
            funder_identifier: 'https://doi.org/10.13039/501100001659',
            funder_identifier_type: 'Crossref Funder ID',
            scheme_uri: 'https://doi.org/10.13039/',
            normalized_crossref_funder_id: '501100001659',
            canonical_crossref_funder_identifier: 'https://doi.org/10.13039/501100001659',
            award_number: 'DFG-EXAMPLE',
            award_uri: 'https://gepris.dfg.de/gepris/OCTOPUS',
            award_title: 'Existing award metadata must remain untouched',
        },
        proposed: {
            funder_identifier: 'https://ror.org/018mejw64',
            funder_identifier_type: 'ROR',
            scheme_uri: 'https://ror.org/',
            ror_id: 'https://ror.org/018mejw64',
            ror_display_name: 'Deutsche Forschungsgemeinschaft',
            ror_status: 'active',
            ror_types: ['funder', 'nonprofit'],
            matched_external_id: {
                type: 'fundref',
                value: '501100001659',
                matched_in: 'external_ids[type=fundref].all',
                preferred: '501100001659',
            },
        },
        provenance: {
            source: 'ror_fundref_index',
            source_file: 'ror/ror-fundref-index.json',
            source_retrieved_at: '2026-06-24T00:00:00Z',
            matching_strategy: 'exact_fundref_external_id',
        },
        confidence: {
            level: 'high',
            score: 1,
            evidence: ['exact_fundref_external_id_match', 'single_active_ror_candidate'],
        },
        ambiguity: {
            status: 'none',
            candidate_count: 1,
            notes: [],
            warnings: [],
        },
        acceptance: {
            updates: {
                funder_identifier: 'https://ror.org/018mejw64',
                funder_identifier_type: 'ROR',
                scheme_uri: 'https://ror.org/',
            },
            preserve: ['funder_name', 'award_number', 'award_uri', 'award_title'],
            preconditions: ['target funding reference still exists'],
        },
    };

    const { metadata: metadataOverride, ...rest } = overrides;

    return {
        id: 86,
        resource_id: 50,
        resource_doi: '10.5880/test.2026.086',
        resource_title: 'Crossref Funder ROR example resource',
        target_type: 'funding_reference',
        target_id: 860,
        suggested_value: 'https://ror.org/018mejw64',
        suggested_label: 'Deutsche Forschungsgemeinschaft -> https://ror.org/018mejw64',
        similarity_score: 1,
        metadata: metadataOverride ?? metadata,
        discovered_at: '2026-06-24T10:00:00+00:00',
        ...rest,
    };
}
function makeSubjectMetadataEnrichmentSuggestion(
    overrides: Partial<SuggestedSubjectMetadataEnrichmentItem> = {},
): SuggestedSubjectMetadataEnrichmentItem {
    const metadata: SuggestedSubjectMetadataEnrichmentItem['metadata'] = {
        contract_version: '1.0',
        issue: 813,
        current: {
            subject_id: 814,
            resource_id: 60,
            value: 'multi-scale laboratories',
            subject_scheme: null,
            normalized_subject_scheme: null,
            scheme_uri: null,
            value_uri: null,
            classification_code: null,
            breadcrumb_path: null,
            language: 'en',
            is_controlled: false,
        },
        proposed: {
            subject_scheme: 'EPOS MSL vocabulary',
            scheme_uri: 'https://epos-msl.uu.nl/voc',
            value_uri: 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
            classification_code: null,
            breadcrumb_path: 'multi-scale laboratories',
            label: 'multi-scale laboratories',
            language: 'en',
            updates: {
                subject_scheme: 'EPOS MSL vocabulary',
                scheme_uri: 'https://epos-msl.uu.nl/voc',
                value_uri: 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
                breadcrumb_path: 'multi-scale laboratories',
            },
            preserve: ['value', 'resource_id'],
        },
        vocabulary: {
            scheme: 'EPOS MSL vocabulary',
            scheme_uri: 'https://epos-msl.uu.nl/voc',
            source: 'utrecht_msl_vocabulary',
            local_cache_file: 'msl-vocabulary.json',
            local_cache_updated_at: '2026-07-04T00:00:00Z',
        },
        match: {
            strategy: 'global_exact_label',
            input: 'multi-scale laboratories',
            normalized_input: 'multi-scale laboratories',
            matched_fields: ['value'],
            candidate_count: 1,
            suppression_reason: null,
        },
        provenance: {
            source: 'utrecht_msl_vocabulary',
            source_file: 'msl-vocabulary.json',
            source_retrieved_at: '2026-07-04T00:00:00Z',
            matching_strategy: 'global_exact_label',
        },
        confidence: {
            level: 'high',
            score: 1,
            evidence: ['globally_unique_free_keyword_match', 'single_candidate', 'source_cache_recorded'],
        },
        ambiguity: {
            status: 'warning',
            candidate_count: 1,
            candidate_ids: ['https://epos-msl.uu.nl/voc/multi-scale-laboratories'],
            notes: [],
            warnings: ['free_keyword_can_be_transferred_to_thesaurus_keyword'],
            warning_messages: {
                free_keyword_can_be_transferred_to_thesaurus_keyword:
                    'This Free Keyword could be transferred into a Thesaurus Keyword if you accept this suggestion.',
            },
        },
        acceptance: {
            updates: ['subject_scheme', 'scheme_uri', 'value_uri', 'breadcrumb_path'],
            preconditions: ['target subject still exists', 'matching strategy still resolves exactly one candidate'],
            stale_if: ['subject value changed', 'source cache was refreshed and candidate no longer resolves uniquely'],
            implementation_issue: 814,
        },
    };

    const { metadata: metadataOverride, ...rest } = overrides;

    return {
        id: 814,
        resource_id: 60,
        resource_doi: '10.5880/test.2026.814',
        resource_title: 'Subject metadata enrichment example resource',
        target_type: 'subject',
        target_id: 814,
        suggested_value: 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
        suggested_label: 'Transfer Free Keyword "multi-scale laboratories" to EPOS MSL vocabulary',
        similarity_score: 1,
        metadata: metadataOverride ?? metadata,
        discovered_at: '2026-07-04T10:00:00+00:00',
        ...rest,
    };
}
function makeDescriptionSegmentationSuggestion(overrides: Partial<SuggestedDescriptionSegmentationItem> = {}): SuggestedDescriptionSegmentationItem {
    const metadata: SuggestedDescriptionSegmentationItem['metadata'] = {
        contract_version: '1.0',
        issue: 816,
        policy_version: 'issue-815-v1',
        current: {
            description_id: 815,
            resource_id: 70,
            description_type: 'Abstract',
            value: 'Legacy overview paragraph that explains the dataset scope and context.\n\nMethods:\nStations were installed and calibrated.\n\nTechnical information:\nCSV and NetCDF files are included.',
            value_hash: 'abc123',
            language: 'en',
        },
        proposed: {
            remaining_abstract: 'Legacy overview paragraph that explains the dataset scope and context.',
            target_types: ['Methods', 'TechnicalInfo'],
            segments: [
                {
                    description_type: 'Methods',
                    value: 'Stations were installed and calibrated with quality control procedures before processing.',
                    language: 'en',
                    confidence: 'medium',
                    confidence_score: 0.65,
                    evidence_label: 'Methods',
                    evidence_types: ['heading'],
                },
                {
                    description_type: 'TechnicalInfo',
                    value: 'CSV and NetCDF files are included with coordinate metadata and processing history files.',
                    language: 'en',
                    confidence: 'medium',
                    confidence_score: 0.65,
                    evidence_label: 'Technical information',
                    evidence_types: ['heading'],
                },
            ],
        },
        confidence: {
            level: 'medium',
            score: 0.65,
            evidence: ['heading'],
        },
        acceptance: {
            updates: {
                source_description: 'replace_abstract_value',
                new_descriptions: ['Methods', 'TechnicalInfo'],
            },
            preconditions: ['source description still exists', 'source description text still matches the reviewed preview hash'],
            stale_if: ['source Abstract text changed'],
        },
    };

    const { metadata: metadataOverride, ...rest } = overrides;

    return {
        id: 815,
        resource_id: 70,
        resource_doi: '10.5880/test.2026.815',
        resource_title: 'Description segmentation example resource',
        target_type: 'description',
        target_id: 815,
        suggested_value: 'description-segmentation:abc123',
        suggested_label: 'Split Abstract into Methods, TechnicalInfo',
        similarity_score: 0.65,
        metadata: metadataOverride ?? metadata,
        discovered_at: '2026-07-05T10:00:00+00:00',
        ...rest,
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

describe('Assistance resource header links', () => {
    it('delineates each resource in a card with a compact suggestion table', () => {
        const suggestions = [
            makeSizeFormatSuggestion({ id: 41, resource_id: 41, resource_doi: '10.5880/test.2026.041' }),
            makeSizeFormatSuggestion({ id: 42, resource_id: 42, resource_doi: '10.5880/test.2026.042' }),
        ];

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated(suggestions) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByTestId(`resource-card-${SIZE_FORMAT_ASSISTANT_ID}-41`)).toBeInTheDocument();
        expect(screen.getByTestId(`resource-card-${SIZE_FORMAT_ASSISTANT_ID}-42`)).toBeInTheDocument();
        expect(screen.getAllByRole('table')).toHaveLength(2);
        expect(screen.getAllByRole('columnheader', { name: 'Suggestion' })).toHaveLength(2);
    });

    it('names the assistant on its check button', () => {
        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByRole('button', { name: `Check ${SIZE_FORMAT_ASSISTANT_NAME}` })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check' })).not.toBeInTheDocument();
    });

    it('renders the resource DOI as a visible editor link', () => {
        const suggestion = makeSizeFormatSuggestion();

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/test.2026.004' });

        expect(link).toHaveAttribute('href', '/editor?resourceId=40');
        expect(link).toHaveAttribute('title', 'Open 10.5880/test.2026.004 in editor');
        expect(link).toHaveClass('text-primary', 'underline');
    });

    it('links each resource group to its own editor target', () => {
        const suggestions = [
            makeSizeFormatSuggestion({
                id: 41,
                resource_id: 41,
                resource_doi: '10.5880/test.2026.041',
                resource_title: 'First resource',
            }),
            makeSizeFormatSuggestion({
                id: 42,
                resource_id: 42,
                resource_doi: '10.5880/test.2026.042',
                resource_title: 'Second resource',
            }),
        ];

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated(suggestions) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByRole('link', { name: '10.5880/test.2026.041' })).toHaveAttribute('href', '/editor?resourceId=41');
        expect(screen.getByRole('link', { name: '10.5880/test.2026.042' })).toHaveAttribute('href', '/editor?resourceId=42');
    });

    it('keeps the editor reachable with fallback labels when a suggestion has no DOI or title', () => {
        const suggestion = makeSizeFormatSuggestion({
            resource_id: 99,
            resource_doi: undefined,
            resource_title: undefined,
        });

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        const link = screen.getByRole('link', { name: 'Resource #99' });

        expect(link).toHaveAttribute('href', '/editor?resourceId=99');
        expect(link).toHaveAttribute('title', 'Open Resource #99 in editor');
        expect(screen.getByText(/Untitled/)).toBeInTheDocument();
    });

    it('promotes the first non-empty resource DOI and title from later grouped suggestions', () => {
        const suggestions = [
            makeSizeFormatSuggestion({
                id: 101,
                resource_id: 101,
                resource_doi: '   ',
                resource_title: '   ',
                suggested_label: 'First blank metadata suggestion',
            }),
            makeSizeFormatSuggestion({
                id: 102,
                resource_id: 101,
                resource_doi: '  10.5880/promoted.2026.102  ',
                resource_title: '  Promoted later title  ',
                suggested_label: 'Second suggestion for same resource',
            }),
        ];

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated(suggestions) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/promoted.2026.102' });

        expect(link).toHaveAttribute('href', '/editor?resourceId=101');
        expect(link).toHaveAttribute('title', 'Open 10.5880/promoted.2026.102 in editor');
        expect(screen.getByText(/Promoted later title/)).toBeInTheDocument();
        expect(screen.getByText('2 suggestion(s)')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Accept' })).toHaveLength(2);
        expect(screen.queryByRole('link', { name: 'Resource #101' })).not.toBeInTheDocument();
    });

    it('keeps the first non-empty resource DOI and title when later grouped suggestions conflict', () => {
        const suggestions = [
            makeSizeFormatSuggestion({
                id: 201,
                resource_id: 201,
                resource_doi: '10.5880/original.2026.201',
                resource_title: 'Original grouped title',
                suggested_label: 'Original metadata suggestion',
            }),
            makeSizeFormatSuggestion({
                id: 202,
                resource_id: 201,
                resource_doi: '10.5880/conflict.2026.201',
                resource_title: 'Conflicting grouped title',
                suggested_label: 'Conflicting metadata suggestion',
            }),
        ];

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated(suggestions) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        const link = screen.getByRole('link', { name: '10.5880/original.2026.201' });

        expect(link).toHaveAttribute('href', '/editor?resourceId=201');
        expect(screen.getByText(/Original grouped title/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: '10.5880/conflict.2026.201' })).not.toBeInTheDocument();
        expect(screen.queryByText(/Conflicting grouped title/)).not.toBeInTheDocument();
    });
});

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
        expect(screen.getByRole('link', { name: 'SPDX reference' })).toHaveAttribute('href', 'https://spdx.org/licenses/CC-BY-4.0.html');
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

        mockedAxiosPost.mockResolvedValueOnce({ data: { success: true, message: 'SPDX suggestion accepted.' } }).mockResolvedValueOnce({ data: {} });

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

    it('renders ZIP content listing suggestions as normal content formats', () => {
        const suggestion = makeSizeFormatSuggestion({
            target_type: 'format',
            suggested_value: 'text/csv',
            suggested_label: 'FORMAT: text/csv',
            metadata: {
                source_url: 'https://datapub.gfz.de/download/archive.zip',
                probe_method: 'ZIP_CONTENT_LISTING',
                confidence: 'medium',
                evidence: {
                    filename: 'data/table.csv',
                    archive_filename: 'archive.zip',
                },
            },
        });

        render(
            <AssistancePage
                sections={{ [SIZE_FORMAT_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SIZE_FORMAT_ASSISTANT_ID, SIZE_FORMAT_ROUTE_PREFIX, SIZE_FORMAT_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('File format')).toBeInTheDocument();
        expect(screen.getByText('Suggested format: CSV file (text/csv)')).toBeInTheDocument();
        expect(screen.getByText('Read from ZIP contents')).toBeInTheDocument();
        expect(screen.queryByText('ZIP Archive')).not.toBeInTheDocument();
        expect(screen.getByText('Detected from file: data/table.csv')).toBeInTheDocument();
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

        mockedAxiosPost.mockResolvedValueOnce({ data: { success: true, message: 'Format applied.' } }).mockResolvedValueOnce({ data: {} });

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

describe('CrossrefFunderRorSuggestionCard - identifier normalization preview', () => {
    it('shows current Crossref state beside the proposed ROR state', () => {
        const suggestion = makeCrossrefFunderRorSuggestion();

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Current Crossref Funder ID')).toBeInTheDocument();
        expect(screen.getByText('Proposed ROR identifier')).toBeInTheDocument();
        expect(screen.getAllByText('Deutsche Forschungsgemeinschaft')).not.toHaveLength(0);
        expect(screen.getByText('https://doi.org/10.13039/501100001659')).toBeInTheDocument();
        expect(screen.getAllByText('https://ror.org/018mejw64')).not.toHaveLength(0);
        expect(screen.getByText('Crossref Funder ID')).toBeInTheDocument();
        expect(screen.getByText('ROR')).toBeInTheDocument();
        expect(screen.getByText('Preserved fields: funder_name, award_number, award_uri, award_title.')).toBeInTheDocument();
    });

    it('renders provenance and exact FundRef evidence for review', () => {
        const suggestion = makeCrossrefFunderRorSuggestion();

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Matched FundRef: 501100001659')).toBeInTheDocument();
        expect(screen.getByText('Evidence: external_ids[type=fundref].all')).toBeInTheDocument();
        expect(screen.getByText('Source: ror_fundref_index')).toBeInTheDocument();
        expect(screen.getByText('File: ror/ror-fundref-index.json')).toBeInTheDocument();
        expect(screen.getByText('Strategy: exact_fundref_external_id')).toBeInTheDocument();
        expect(screen.getByText(/exact_fundref_external_id_match/)).toBeInTheDocument();
    });

    it('renders warning codes when an exact mapping still needs curator attention', () => {
        const suggestion = makeCrossrefFunderRorSuggestion({
            metadata: {
                ...makeCrossrefFunderRorSuggestion().metadata!,
                ambiguity: {
                    status: 'warning',
                    candidate_count: 1,
                    notes: [],
                    warnings: ['local_name_not_found_in_ror_names', 'ror_display_name_differs_from_local_name'],
                },
            },
        });

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Ambiguity: warning')).toBeInTheDocument();
        expect(screen.getByText('local_name_not_found_in_ror_names')).toBeInTheDocument();
        expect(screen.getByText('ror_display_name_differs_from_local_name')).toBeInTheDocument();
    });

    it('renders sparse Crossref Funder ROR metadata with fallback labels', () => {
        const sparseMetadata: SuggestedCrossrefFunderRorItem['metadata'] = {
            current: {},
            proposed: {
                ror_id: 'not-a-ror-url',
                ror_types: ['funder', '', { ignored: true }] as unknown as string[],
            },
            confidence: {
                level: 'low',
                evidence: ['single_active_ror_candidate', '', { ignored: true }] as unknown as string[],
            },
            ambiguity: {
                status: 'none',
                warnings: [],
            },
            acceptance: {
                preserve: [],
            },
        };
        const suggestion = makeCrossrefFunderRorSuggestion({
            suggested_value: 'not-a-ror-url',
            suggested_label: 'Sparse mapping suggestion',
            similarity_score: null,
            metadata: sparseMetadata,
            discovered_at: '',
        });

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Sparse mapping suggestion')).toBeInTheDocument();
        expect(screen.getByText('Low confidence')).toBeInTheDocument();
        expect(screen.getByText('No ambiguity')).toBeInTheDocument();
        expect(screen.getByText('No metadata captured.')).toBeInTheDocument();
        expect(screen.getByText('funder')).toBeInTheDocument();
        expect(screen.getByText('Confidence evidence: single_active_ror_candidate')).toBeInTheDocument();
        expect(screen.getByText('Discovered: -')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'not-a-ror-url' })).not.toBeInTheDocument();
        expect(screen.queryByText(/Preserved fields:/)).not.toBeInTheDocument();
        expect(screen.queryByText(/registry match/)).not.toBeInTheDocument();
    });
    it('renders canonical ROR identifiers as safe links', () => {
        const suggestion = makeCrossrefFunderRorSuggestion();

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByRole('link', { name: 'https://ror.org/018mejw64' })).toHaveAttribute('href', 'https://ror.org/018mejw64');
    });

    it('renders hostile ROR-like values as plain text', () => {
        const baseMetadata = makeCrossrefFunderRorSuggestion().metadata!;
        const suggestion = makeCrossrefFunderRorSuggestion({
            suggested_value: 'javascript:alert(1)',
            metadata: {
                ...baseMetadata,
                proposed: {
                    ...baseMetadata.proposed,
                    funder_identifier: 'javascript:alert(1)',
                },
            },
        });

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.queryByRole('link', { name: 'javascript:alert(1)' })).not.toBeInTheDocument();
        expect(screen.getAllByText(/javascript:alert/)).not.toHaveLength(0);
    });

    it('posts accept and decline requests through the Crossref Funder ROR route prefix', async () => {
        const suggestion = makeCrossrefFunderRorSuggestion({ id: 860 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({ data: { success: true, message: 'Funding reference identifier normalized to ROR.' } })
            .mockResolvedValueOnce({ data: {} });

        render(
            <AssistancePage
                sections={{ [CROSSREF_FUNDER_ROR_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(CROSSREF_FUNDER_ROR_ASSISTANT_ID, CROSSREF_FUNDER_ROR_ROUTE_PREFIX, CROSSREF_FUNDER_ROR_ASSISTANT_NAME)]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/crossref-funder-rors/860/accept');
        });

        await user.click(screen.getByRole('button', { name: 'Decline' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/crossref-funder-rors/860/decline');
        });
    });
});
describe('SubjectMetadataEnrichmentCard - DataCite Subject preview', () => {
    it('shows current subject metadata beside the fields that will be updated', () => {
        const suggestion = makeSubjectMetadataEnrichmentSuggestion();

        render(
            <AssistancePage
                sections={{ [SUBJECT_METADATA_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SUBJECT_METADATA_ASSISTANT_ID, SUBJECT_METADATA_ROUTE_PREFIX, SUBJECT_METADATA_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Current Subject metadata')).toBeInTheDocument();
        expect(screen.getByText('Will update DataCite Subject fields')).toBeInTheDocument();
        expect(screen.getAllByText('multi-scale laboratories')).not.toHaveLength(0);
        expect(screen.getByText('EPOS MSL vocabulary')).toBeInTheDocument();
        expect(screen.getByText('https://epos-msl.uu.nl/voc')).toBeInTheDocument();
        expect(screen.getAllByText('https://epos-msl.uu.nl/voc/multi-scale-laboratories')).not.toHaveLength(0);
        expect(screen.getByText('Preserved fields: value, resource_id.')).toBeInTheDocument();
    });

    it('renders free keyword transfer warnings and provenance evidence', () => {
        const suggestion = makeSubjectMetadataEnrichmentSuggestion();

        render(
            <AssistancePage
                sections={{ [SUBJECT_METADATA_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SUBJECT_METADATA_ASSISTANT_ID, SUBJECT_METADATA_ROUTE_PREFIX, SUBJECT_METADATA_ASSISTANT_NAME)]}
            />,
        );

        expect(screen.getByText('Ambiguity: warning')).toBeInTheDocument();
        expect(
            screen.getByText('This Free Keyword could be transferred into a Thesaurus Keyword if you accept this suggestion.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Vocabulary: EPOS MSL vocabulary')).toBeInTheDocument();
        expect(screen.getByText('Source: utrecht_msl_vocabulary')).toBeInTheDocument();
        expect(screen.getByText('File: msl-vocabulary.json')).toBeInTheDocument();
        expect(screen.getByText('Strategy: global_exact_label')).toBeInTheDocument();
        expect(screen.getByText('Matched fields: value')).toBeInTheDocument();
        expect(screen.getByText('Candidates: 1')).toBeInTheDocument();
        expect(screen.getByText(/globally_unique_free_keyword_match/)).toBeInTheDocument();
    });

    it('posts accept and decline requests through the subject metadata route prefix', async () => {
        const suggestion = makeSubjectMetadataEnrichmentSuggestion({ id: 914 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({ data: { success: true, message: 'Subject metadata enrichment applied.' } })
            .mockResolvedValueOnce({ data: {} });

        render(
            <AssistancePage
                sections={{ [SUBJECT_METADATA_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[makeManifest(SUBJECT_METADATA_ASSISTANT_ID, SUBJECT_METADATA_ROUTE_PREFIX, SUBJECT_METADATA_ASSISTANT_NAME)]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/subject-metadata-enrichment/914/accept');
        });

        await user.click(screen.getByRole('button', { name: 'Decline' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/subject-metadata-enrichment/914/decline');
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

    it('reloads immediately after accepting a ROR suggestion without bulk matches', async () => {
        const suggestion = makeRorSuggestion({ id: 954 });
        const user = userEvent.setup();

        mockedAxiosPost.mockResolvedValueOnce({
            data: {
                success: true,
                message: 'ROR-ID accepted. No resources required DataCite sync.',
                replaced_identifier: null,
                synced_dois: [],
            },
        });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/rors/954/accept');
            expect(mockedRouterReload).toHaveBeenCalledWith({ only: ['sections', 'pendingAssistanceTotalCount'] });
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });

    it('opens a bulk accept dialog after accepting a ROR affiliation suggestion with exact matches', async () => {
        const suggestion = makeRorSuggestion({ id: 955 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({
                data: {
                    success: true,
                    message: 'ROR-ID accepted. No resources required DataCite sync.',
                    bulk_affiliation_match: {
                        available: true,
                        count: 2,
                        bulk_token: BULK_TOKEN_MATCH,
                        creator_name: 'Doe, Jane',
                        affiliation: 'GFZ Potsdam',
                        suggested_ror_id: 'https://ror.org/04t3en479',
                    },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    success: true,
                    accepted_count: 2,
                    skipped_count: 0,
                    synced_dois: [],
                    message: 'ROR-ID accepted for 2 further creator affiliations.',
                },
            });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/rors/955/accept');
        });

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent(
            'There are 2 further creator affiliations with the same <creatorName>, <affiliation>, and ROR suggestion you have just confirmed. Would you like to accept the ROR suggestion for these affiliations as well?',
        );
        expect(mockedRouterReload).not.toHaveBeenCalled();

        await user.click(within(dialog).getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/rors/bulk-affiliation-accept', {
                bulk_token: BULK_TOKEN_MATCH,
            });
            expect(mockedRouterReload).toHaveBeenCalledWith({ only: ['sections', 'pendingAssistanceTotalCount'] });
        });
    });

    it('uses singular copy for one matching ROR creator affiliation', async () => {
        const suggestion = makeRorSuggestion({ id: 958 });
        const user = userEvent.setup();

        mockedAxiosPost.mockResolvedValueOnce({
            data: {
                success: true,
                message: 'ROR-ID accepted. No resources required DataCite sync.',
                bulk_affiliation_match: {
                    available: true,
                    count: 1,
                    bulk_token: BULK_TOKEN_SINGULAR,
                    creator_name: 'Doe, Jane',
                    affiliation: 'GFZ Potsdam',
                    suggested_ror_id: 'https://ror.org/04t3en479',
                },
            },
        });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent(
            'There is 1 further creator affiliation with the same <creatorName>, <affiliation>, and ROR suggestion you have just confirmed. Would you like to accept the ROR suggestion for this affiliation as well?',
        );
    });
    it('keeps the bulk ROR dialog open so a failed request can be retried', async () => {
        const suggestion = makeRorSuggestion({ id: 957 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({
                data: {
                    success: true,
                    message: 'ROR-ID accepted. No resources required DataCite sync.',
                    bulk_affiliation_match: {
                        available: true,
                        count: 1,
                        bulk_token: BULK_TOKEN_RETRY,
                        creator_name: 'Doe, Jane',
                        affiliation: 'GFZ Potsdam',
                        suggested_ror_id: 'https://ror.org/04t3en479',
                    },
                },
            })
            .mockRejectedValueOnce({
                isAxiosError: true,
                response: {
                    status: 500,
                    data: {
                        message: 'DataCite sync failed. Please try again.',
                    },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    success: true,
                    accepted_count: 0,
                    skipped_count: 0,
                    synced_dois: ['10.5880/test.2024.002'],
                    message: 'ROR-ID acceptance was already applied for 1 further creator affiliation. DataCite sync has been retried.',
                },
            });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/rors/bulk-affiliation-accept', {
                bulk_token: BULK_TOKEN_RETRY,
            });
            expect(mockedToastWarning).toHaveBeenCalledWith('DataCite sync failed. Please try again.');
            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });
        expect(mockedRouterReload).not.toHaveBeenCalled();

        await waitFor(() => {
            expect(within(dialog).getByRole('button', { name: 'Accept' })).not.toBeDisabled();
        });

        await user.click(within(dialog).getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(3, '/assistance/rors/bulk-affiliation-accept', {
                bulk_token: BULK_TOKEN_RETRY,
            });
            expect(mockedRouterReload).toHaveBeenCalledWith({ only: ['sections', 'pendingAssistanceTotalCount'] });
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });

    it('closes and reloads the bulk ROR dialog for non-retryable 422 responses', async () => {
        const suggestion = makeRorSuggestion({ id: 959 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({
                data: {
                    success: true,
                    message: 'ROR-ID accepted. No resources required DataCite sync.',
                    bulk_affiliation_match: {
                        available: true,
                        count: 1,
                        bulk_token: BULK_TOKEN_EXPIRED,
                        creator_name: 'Doe, Jane',
                        affiliation: 'GFZ Potsdam',
                        suggested_ror_id: 'https://ror.org/04t3en479',
                    },
                },
            })
            .mockRejectedValueOnce({
                isAxiosError: true,
                response: {
                    status: 422,
                    data: {
                        message: 'Bulk ROR acceptance token is invalid or has expired.',
                    },
                },
            });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Accept' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/rors/bulk-affiliation-accept', {
                bulk_token: BULK_TOKEN_EXPIRED,
            });
            expect(mockedToastWarning).toHaveBeenCalledWith('Bulk ROR acceptance token is invalid or has expired.');
            expect(mockedRouterReload).toHaveBeenCalledWith({ only: ['sections', 'pendingAssistanceTotalCount'] });
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });
    it('declines the bulk ROR dialog without posting the token', async () => {
        const suggestion = makeRorSuggestion({ id: 956 });
        const user = userEvent.setup();

        mockedAxiosPost.mockResolvedValueOnce({
            data: {
                success: true,
                message: 'ROR-ID accepted. No resources required DataCite sync.',
                bulk_affiliation_match: {
                    available: true,
                    count: 1,
                    bulk_token: BULK_TOKEN_DECLINE,
                    creator_name: 'Doe, Jane',
                    affiliation: 'GFZ Potsdam',
                    suggested_ror_id: 'https://ror.org/04t3en479',
                },
            },
        });

        render(
            <AssistancePage
                sections={{ 'ror-suggestion': paginated([suggestion]) }}
                manifests={[makeManifest('ror-suggestion', 'rors', 'ROR Suggestions')]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Accept' }));

        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Decline' }));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenCalledTimes(1);
            expect(mockedRouterReload).toHaveBeenCalledWith({ only: ['sections', 'pendingAssistanceTotalCount'] });
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });
});

describe('DescriptionSegmentationSuggestionCard - description split preview', () => {
    it('shows the current abstract, proposed abstract, and new description segments', () => {
        const suggestion = makeDescriptionSegmentationSuggestion();

        render(
            <AssistancePage
                sections={{ [DESCRIPTION_SEGMENTATION_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[
                    makeManifest(
                        DESCRIPTION_SEGMENTATION_ASSISTANT_ID,
                        DESCRIPTION_SEGMENTATION_ROUTE_PREFIX,
                        DESCRIPTION_SEGMENTATION_ASSISTANT_NAME,
                    ),
                ]}
            />,
        );

        expect(screen.getByText('Current Abstract')).toBeInTheDocument();
        expect(screen.getByText('Proposed Abstract')).toBeInTheDocument();
        expect(screen.getByText('New Description segments')).toBeInTheDocument();
        expect(screen.getByText('Split Abstract into Methods, Technical Info')).toBeInTheDocument();
        expect(screen.getAllByText('Methods')).not.toHaveLength(0);
        expect(screen.getByText('Methods, Technical Info')).toBeInTheDocument();
        expect(screen.getByText('Technical Info')).toBeInTheDocument();
        expect(screen.queryByText('TechnicalInfo')).not.toBeInTheDocument();
        expect(screen.getAllByText(/Legacy overview paragraph that explains the dataset scope/)).toHaveLength(2);
        expect(screen.getByText(/Stations were installed and calibrated with quality control procedures/)).toBeInTheDocument();
        expect(screen.getByText(/CSV and NetCDF files are included with coordinate metadata/)).toBeInTheDocument();
        expect(screen.getByText(/Accept replaces only the source Abstract text/)).toBeInTheDocument();
        expect(screen.getByText(/Policy: issue-815-v1/)).toBeInTheDocument();
    });

    it('posts accept and decline requests through the description segmentation route prefix', async () => {
        const suggestion = makeDescriptionSegmentationSuggestion({ id: 916 });
        const user = userEvent.setup();

        mockedAxiosPost
            .mockResolvedValueOnce({ data: { success: true, message: 'Description segmentation applied.' } })
            .mockResolvedValueOnce({ data: {} });

        render(
            <AssistancePage
                sections={{ [DESCRIPTION_SEGMENTATION_ASSISTANT_ID]: paginated([suggestion]) }}
                manifests={[
                    makeManifest(
                        DESCRIPTION_SEGMENTATION_ASSISTANT_ID,
                        DESCRIPTION_SEGMENTATION_ROUTE_PREFIX,
                        DESCRIPTION_SEGMENTATION_ASSISTANT_NAME,
                    ),
                ]}
            />,
        );

        await user.click(screen.getByTestId('description-segmentation-accept-916'));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(1, '/assistance/description-segmentation/916/accept');
            expect(screen.getByTestId('description-segmentation-accept-916')).not.toBeDisabled();
        });

        await user.click(screen.getByTestId('description-segmentation-decline-916'));

        await waitFor(() => {
            expect(mockedAxiosPost).toHaveBeenNthCalledWith(2, '/assistance/description-segmentation/916/decline');
        });
    });
});
