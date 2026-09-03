import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalFilters } from '@/components/portal/PortalFilters';
import type { PortalFilters as PortalFilterValues } from '@/types/portal';

vi.mock('@/components/portal/PortalSearchInput', () => ({
    PortalSearchInput: ({
        value,
        onValueChange,
        onSubmit,
        selectedKeywords,
        onKeywordSelect,
        onKeywordsChange,
    }: {
        value: string;
        onValueChange: (value: string) => void;
        onSubmit: (value: string) => void;
        selectedKeywords: string[];
        onKeywordSelect: (keyword: string) => void;
        onKeywordsChange: (keywords: string[]) => void;
    }) => (
        <div data-testid="unified-search">
            <input aria-label="Search text or keywords" value={value} onChange={(event) => onValueChange(event.target.value)} />
            <button onClick={() => onSubmit(value)}>Submit search</button>
            <button onClick={() => onKeywordSelect('Seismology')}>Select suggestion</button>
            {selectedKeywords.map((keyword) => (
                <button key={keyword} onClick={() => onKeywordsChange(selectedKeywords.filter((value) => value !== keyword))}>
                    Remove {keyword}
                </button>
            ))}
        </div>
    ),
}));

const defaultFilters: PortalFilterValues = {
    query: '',
    type: [],
    keywords: [],
    freeKeywords: [],
    thesaurusKeywords: [],
    sampleTypes: [],
    materials: [],
    classifications: [],
    geologicalAges: [],
    geologicalUnits: [],
    datacenter: [],
    bounds: null,
    temporal: null,
};

const thesaurusFacets = [
    {
        scheme: 'Science Keywords',
        roots: [
            {
                id: 'science-root',
                text: 'Science Keywords',
                language: 'en',
                scheme: 'Science Keywords',
                schemeURI: 'https://example.test/science',
                description: '',
                children: [],
            },
        ],
    },
];

const igsnFacets = {
    sampleTypes: [
        { value: 'Core', label: 'Core', count: 12 },
        { value: 'Specimen', label: 'Specimen', count: 7 },
    ],
    materials: [{ value: 'Rock', label: 'Rock', count: 9, children: [] }],
    classifications: [
        {
            type: 'rock' as const,
            label: 'Rock',
            options: [{ value: 'Igneous', label: 'Igneous', count: 5 }],
        },
    ],
    geologicalAges: [{ value: 'Jurassic', label: 'Jurassic', count: 4 }],
    geologicalUnits: [{ value: 'Upper Rhine Graben', label: 'Upper Rhine Graben', count: 3 }],
};

const defaultProps = {
    filters: defaultFilters,
    searchValue: '',
    onSearchValueChange: vi.fn(),
    onSearchChange: vi.fn(),
    onKeywordSelect: vi.fn(),
    onTypeChange: vi.fn(),
    onDatacenterChange: vi.fn(),
    onKeywordsChange: vi.fn(),
    onThesaurusKeywordsChange: vi.fn(),
    onSampleTypesChange: vi.fn(),
    onMaterialsChange: vi.fn(),
    onClassificationsChange: vi.fn(),
    onGeologicalAgesChange: vi.fn(),
    onGeologicalUnitsChange: vi.fn(),
    onClearFilters: vi.fn(),
    hasActiveFilters: false,
    isCollapsed: false,
    onToggleCollapse: vi.fn(),
    thesaurusFacets,
    geoFilterEnabled: false,
    onGeoFilterToggle: vi.fn(),
    onBoundsChange: vi.fn(),
    temporalRange: { Created: { min: 2000, max: 2024 } },
    temporalFilterEnabled: false,
    onTemporalFilterToggle: vi.fn(),
    onTemporalChange: vi.fn(),
    resourceTypeFacets: [{ slug: 'dataset', name: 'Dataset', count: 42 }],
    datacenterFacets: [{ name: 'GFZ', count: 42 }],
};

describe('PortalFilters', () => {
    beforeEach(() => vi.clearAllMocks());

    it('keeps the unified search above the single scrolling filter region', () => {
        render(<PortalFilters {...defaultProps} />);

        const search = screen.getByTestId('unified-search');
        const scrollArea = search.closest('aside')?.querySelector('[data-slot="scroll-area"]');

        expect(scrollArea).toBeInTheDocument();
        expect(search.closest('[data-slot="scroll-area"]')).toBeNull();
        expect(screen.getAllByTestId('unified-search')).toHaveLength(1);
        expect(screen.queryByText(/results|counting/i)).not.toBeInTheDocument();
    });

    it('opens thesaurus roots initially and keeps the remaining filter groups collapsed', () => {
        render(<PortalFilters {...defaultProps} />);

        expect(screen.getByText('Science Keywords')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /sample type/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^material/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^classification/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /geological age/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /geological unit/i })).not.toBeInTheDocument();
        expect(screen.queryByText('All Resource Types')).not.toBeInTheDocument();
        expect(screen.queryByText('All Datacenters')).not.toBeInTheDocument();
    });

    it('opens a compact filter group on demand', async () => {
        const user = userEvent.setup();
        render(<PortalFilters {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /resource type/i }));

        expect(screen.getByText('All Resource Types')).toBeInTheDocument();
    });

    it('removes the complete resource type section in the IGSN portal', () => {
        render(
            <PortalFilters
                {...defaultProps}
                basePath="/igsn-search"
                showResourceTypeFilter={false}
                resourceTypeFacets={[]}
                igsnFacets={igsnFacets}
            />,
        );

        expect(screen.queryByRole('button', { name: /resource type/i })).not.toBeInTheDocument();
        expect(screen.queryByText('All Resource Types')).not.toBeInTheDocument();
    });

    it('replaces the IGSN thesaurus with all five counted metadata filters', async () => {
        const user = userEvent.setup();
        render(<PortalFilters {...defaultProps} basePath="/igsn-search" igsnFacets={igsnFacets} showResourceTypeFilter={false} />);

        expect(screen.queryByRole('button', { name: /thesaurus keywords/i })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /sample type/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^material/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /classification/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /geological age/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /geological unit/i })).toBeInTheDocument();
        expect(screen.getByText('Core')).toBeInTheDocument();
        expect(screen.getByText('12')).toBeInTheDocument();

        await user.click(screen.getByRole('checkbox', { name: 'Select Core' }));

        expect(defaultProps.onSampleTypesChange).toHaveBeenCalledWith(['Core']);
    });

    it('opens an active IGSN group and shows its selected-value badge', () => {
        render(
            <PortalFilters
                {...defaultProps}
                basePath="/igsn-search"
                igsnFacets={igsnFacets}
                filters={{ ...defaultFilters, materials: ['Rock'] }}
                hasActiveFilters
            />,
        );

        expect(screen.getByRole('tree', { name: 'Materials' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^material1$/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove Rock' })).toBeInTheDocument();
    });

    it('automatically opens groups that contain active filters and displays a count', () => {
        render(<PortalFilters {...defaultProps} filters={{ ...defaultFilters, type: ['dataset'] }} hasActiveFilters />);

        expect(screen.getByText('1 selected')).toBeInTheDocument();
        expect(screen.getAllByText('1').length).toBeGreaterThan(0);
    });

    it('forwards unified text and keyword actions', async () => {
        const user = userEvent.setup();
        render(<PortalFilters {...defaultProps} searchValue="climate" />);

        await user.click(screen.getByRole('button', { name: 'Submit search' }));
        await user.click(screen.getByRole('button', { name: 'Select suggestion' }));

        expect(defaultProps.onSearchChange).toHaveBeenCalledWith('climate');
        expect(defaultProps.onKeywordSelect).toHaveBeenCalledWith('Seismology');
    });

    it('shows legacy keyword chips in the unified search', () => {
        render(<PortalFilters {...defaultProps} filters={{ ...defaultFilters, keywords: ['Legacy keyword'] }} />);

        expect(screen.getByRole('button', { name: 'Remove Legacy keyword' })).toBeInTheDocument();
    });

    it('clears all filters from the compact header', async () => {
        const user = userEvent.setup();
        render(<PortalFilters {...defaultProps} hasActiveFilters />);

        await user.click(screen.getByRole('button', { name: 'Clear all filters' }));

        expect(defaultProps.onClearFilters).toHaveBeenCalledOnce();
    });

    it('supports the collapsed desktop rail', async () => {
        const user = userEvent.setup();
        render(<PortalFilters {...defaultProps} isCollapsed hasActiveFilters />);

        expect(screen.queryByTestId('unified-search')).not.toBeInTheDocument();
        expect(screen.getByRole('status', { name: 'Filters active' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Expand filters' }));
        expect(defaultProps.onToggleCollapse).toHaveBeenCalledOnce();
    });

    it('can hide the collapse action inside a drawer', () => {
        render(<PortalFilters {...defaultProps} showCollapseButton={false} />);

        expect(screen.queryByRole('button', { name: 'Collapse filters' })).not.toBeInTheDocument();
    });
});
