import { fireEvent, render, screen, within } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { SampleFamilySection } from '@/pages/LandingPages/components/SampleFamilySection';
import type { LandingPageIgsnSampleFamily } from '@/types/landing-page';

const family: LandingPageIgsnSampleFamily = {
    member_count: 4,
    root: {
        resource_id: 1,
        name: 'Station Alpha',
        igsn: 'GFROOT001',
        sample_type: 'Hole',
        landing_page: { public_url: 'https://example.test/root' },
        children: [
            {
                resource_id: 2,
                name: 'Core A',
                igsn: 'GFCORE001',
                sample_type: 'Core',
                landing_page: { public_url: 'https://example.test/core-a' },
                children: [
                    {
                        resource_id: 4,
                        name: 'Sample 1',
                        igsn: 'GFSAMPLE01',
                        sample_type: 'Individual Sample',
                        landing_page: null,
                        children: [],
                    },
                ],
            },
            {
                resource_id: 3,
                name: null,
                igsn: 'GFCORE002',
                sample_type: null,
                landing_page: null,
                children: [],
            },
        ],
    },
};

describe('SampleFamilySection', () => {
    it('renders nothing without a connected family', () => {
        const { container, rerender } = render(<SampleFamilySection family={null} currentResourceId={1} />);
        expect(container.firstChild).toBeNull();

        rerender(<SampleFamilySection currentResourceId={1} family={{ member_count: 1, root: { ...family.root, children: [] } }} />);
        expect(container.firstChild).toBeNull();
    });

    it('initially expands only the current branch and exposes accessible toggles for every parent', () => {
        render(<SampleFamilySection family={family} currentResourceId={2} />);

        expect(screen.getByRole('heading', { name: 'Sample Family' })).toBeInTheDocument();
        const navigation = screen.getByRole('navigation', { name: 'Sample Family' });
        expect(within(navigation).getAllByRole('listitem')).toHaveLength(3);
        expect(screen.queryByRole('tree')).not.toBeInTheDocument();
        expect(screen.queryByRole('treeitem')).not.toBeInTheDocument();
        expect(screen.queryByText(/Complete sampling hierarchy known to ERNIE/i)).not.toBeInTheDocument();
        expect(screen.getByText('Station Alpha')).toBeInTheDocument();
        expect(screen.getByText('IGSN GFROOT001')).toBeInTheDocument();
        expect(screen.queryByText('Sample 1')).not.toBeInTheDocument();
        const rootToggle = screen.getByRole('button', { name: 'Collapse Station Alpha' });
        expect(rootToggle).toHaveAttribute('aria-expanded', 'true');
        const coreToggle = screen.getByRole('button', { name: 'Expand Core A' });
        expect(coreToggle).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(coreToggle);
        expect(screen.getByText('Sample 1')).toBeInTheDocument();
        expect(screen.getByText('IGSN GFSAMPLE01')).toBeInTheDocument();
        expect(screen.queryByText('Individual Sample')).not.toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Sample type: Hole' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Sample type: Core' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Sample type: Individual Sample' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Sample type: Unknown' })).toBeInTheDocument();
    });

    it('links only published non-current nodes and marks the current sample accessibly', () => {
        render(<SampleFamilySection family={family} currentResourceId={2} />);

        expect(screen.getByRole('link', { name: /Station Alpha.*GFROOT001/i })).toHaveAttribute('href', 'https://example.test/root');
        expect(screen.queryByRole('link', { name: /Core A/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Sample 1/i })).not.toBeInTheDocument();

        const currentItem = screen.getByRole('listitem', { current: 'page' });
        expect(within(currentItem).getByText('Core A')).toBeInTheDocument();
        expect(within(currentItem).getByText('Current sample')).toBeInTheDocument();
    });

    it('gives every sample type in the legacy IGSN index a distinct icon', () => {
        const legacyTypes = [
            ['individual sample', 'individual-sample'],
            ['core whole round', 'core-whole-round'],
            ['core section', 'core-section'],
            ['specimen', 'specimen'],
            ['core', 'core'],
            ['site', 'site'],
            ['core sample', 'core-sample'],
            ['cuttings', 'cuttings'],
            ['ctd', 'ctd'],
            ['terrestrial section', 'terrestrial-section'],
            ['core half round', 'core-half-round'],
            ['grab', 'grab'],
            ['hole', 'hole'],
            ['dredge', 'dredge'],
            ['other', 'other'],
        ] as const;
        const iconFamily: LandingPageIgsnSampleFamily = {
            member_count: legacyTypes.length,
            root: {
                resource_id: 100,
                name: 'Legacy type root',
                igsn: 'GFLEGACYROOT',
                sample_type: legacyTypes[0][0],
                landing_page: null,
                children: legacyTypes.slice(1).map(([sampleType], index) => ({
                    resource_id: 101 + index,
                    name: `Legacy type ${index + 1}`,
                    igsn: `GFLEGACY${index + 1}`,
                    sample_type: sampleType,
                    landing_page: null,
                    children: [],
                })),
            },
        };

        render(<SampleFamilySection family={iconFamily} currentResourceId={101} />);

        for (const [sampleType, iconKind] of legacyTypes) {
            expect(screen.getByRole('img', { name: `Sample type: ${sampleType}` })).toHaveAttribute('data-sample-type-icon', iconKind);
        }
    });

    it('uses the IGSN as the primary label when a sample name is missing', () => {
        render(<SampleFamilySection family={family} currentResourceId={3} />);

        expect(screen.getByText('GFCORE002')).toBeInTheDocument();
        expect(screen.queryByText('IGSN GFCORE002')).not.toBeInTheDocument();
    });

    it('uses a neutral fallback when both name and IGSN are unavailable', () => {
        const incomplete: LandingPageIgsnSampleFamily = {
            member_count: 2,
            root: {
                ...family.root,
                children: [{ resource_id: 9, name: null, igsn: null, sample_type: null, landing_page: null, children: [] }],
            },
        };

        render(<SampleFamilySection family={incomplete} currentResourceId={9} />);

        expect(screen.getByText('Unnamed sample')).toBeInTheDocument();
    });

    it('provides a keyboard-operable resize grip without persisting the chosen height', () => {
        render(<SampleFamilySection family={family} currentResourceId={2} />);

        const grip = screen.getByRole('separator', { name: 'Resize Sample Family' });
        expect(grip).toHaveAttribute('aria-orientation', 'horizontal');
        expect(grip).toHaveAttribute('aria-valuenow');

        fireEvent.keyDown(grip, { key: 'Home' });
        expect(grip).toHaveAttribute('aria-valuenow', grip.getAttribute('aria-valuemin'));
        fireEvent.keyDown(grip, { key: 'End' });
        expect(grip).toHaveAttribute('aria-valuenow', grip.getAttribute('aria-valuemax'));
    });
});
