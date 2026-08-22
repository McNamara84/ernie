import { render, screen, within } from '@testing-library/react';
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

    it('renders the complete nested hierarchy with names and IGSNs', () => {
        render(<SampleFamilySection family={family} currentResourceId={2} />);

        expect(screen.getByRole('heading', { name: 'Sample Family' })).toBeInTheDocument();
        expect(screen.getByRole('tree', { name: 'Sample family hierarchy' })).toBeInTheDocument();
        expect(screen.getAllByRole('treeitem')).toHaveLength(4);
        expect(screen.getByText('Station Alpha')).toBeInTheDocument();
        expect(screen.getByText('IGSN GFROOT001')).toBeInTheDocument();
        expect(screen.getByText('Sample 1')).toBeInTheDocument();
        expect(screen.getByText('IGSN GFSAMPLE01')).toBeInTheDocument();
        expect(screen.getByText('Individual Sample')).toBeInTheDocument();
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

        const currentItem = screen.getByRole('treeitem', { current: 'page' });
        expect(within(currentItem).getByText('Core A')).toBeInTheDocument();
        expect(within(currentItem).getByText('Current sample')).toBeInTheDocument();
    });

    it('uses the IGSN as the primary label when a sample name is missing', () => {
        render(<SampleFamilySection family={family} currentResourceId={1} />);

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

        render(<SampleFamilySection family={incomplete} currentResourceId={1} />);

        expect(screen.getByText('Unnamed sample')).toBeInTheDocument();
    });
});
