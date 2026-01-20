import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { LicenseResourceTypePopover } from '@/components/settings/license-resource-type-popover';

const mockResourceTypes = [
    { id: 1, name: 'Dataset' },
    { id: 2, name: 'Software' },
    { id: 3, name: 'Book' },
];

describe('LicenseResourceTypePopover', () => {
    it('renders trigger button', () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={() => {}}
            />,
        );

        expect(screen.getByRole('button')).toBeInTheDocument();
    });

    it('shows "All" when no exclusions', () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={() => {}}
            />,
        );

        expect(screen.getByText('All')).toBeInTheDocument();
    });

    it('shows count badge when exclusions exist', () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1]}
                onExcludedChange={() => {}}
            />,
        );

        // 2 available out of 3 total
        expect(screen.getByText('2/3')).toBeInTheDocument();
    });

    it('shows correct count with multiple exclusions', () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1, 2]}
                onExcludedChange={() => {}}
            />,
        );

        // 1 available out of 3 total
        expect(screen.getByText('1/3')).toBeInTheDocument();
    });

    it('has correct aria-label', () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT License"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={() => {}}
            />,
        );

        expect(screen.getByRole('button')).toHaveAttribute('aria-label', 'Configure resource types for MIT License');
    });

    it('opens popover on click', async () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        // Should show the header text
        expect(await screen.findByText('Resource Type Restrictions')).toBeInTheDocument();
    });

    it('shows all resource types when popover is open', async () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Dataset')).toBeInTheDocument();
        expect(screen.getByText('Software')).toBeInTheDocument();
        expect(screen.getByText('Book')).toBeInTheDocument();
    });

    it('calls onExcludedChange when toggling resource type to exclude', async () => {
        const handleChange = vi.fn();
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={handleChange}
            />,
        );

        // Open popover
        fireEvent.click(screen.getByRole('button'));

        // Find and click Dataset checkbox (which is currently checked, meaning not excluded)
        const datasetCheckbox = await screen.findByRole('checkbox', { name: 'Dataset' });
        fireEvent.click(datasetCheckbox);

        expect(handleChange).toHaveBeenCalledWith([1]);
    });

    it('calls onExcludedChange when toggling resource type to include', async () => {
        const handleChange = vi.fn();
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1]} // Dataset is excluded
                onExcludedChange={handleChange}
            />,
        );

        // Open popover
        fireEvent.click(screen.getByRole('button'));

        // Find and click Dataset checkbox (which is currently unchecked, meaning excluded)
        const datasetCheckbox = await screen.findByRole('checkbox', { name: 'Dataset' });
        fireEvent.click(datasetCheckbox);

        // Should remove 1 from excluded list
        expect(handleChange).toHaveBeenCalledWith([]);
    });

    it('shows Excluded badge for excluded resource types', async () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1, 2]}
                onExcludedChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        // Should show 2 Excluded badges
        const excludedBadges = await screen.findAllByText('Excluded');
        expect(excludedBadges).toHaveLength(2);
    });

    it('shows Reset button when exclusions exist', async () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1]}
                onExcludedChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Reset')).toBeInTheDocument();
    });

    it('does not show Reset button when no exclusions', async () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[]}
                onExcludedChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        // Wait for popover to open
        await screen.findByText('Resource Type Restrictions');

        expect(screen.queryByText('Reset')).not.toBeInTheDocument();
    });

    it('calls onExcludedChange with empty array when Reset is clicked', async () => {
        const handleChange = vi.fn();
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1, 2]}
                onExcludedChange={handleChange}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        const resetButton = await screen.findByText('Reset');
        fireEvent.click(resetButton);

        expect(handleChange).toHaveBeenCalledWith([]);
    });

    it('shows correct available count in footer', async () => {
        render(
            <LicenseResourceTypePopover
                licenseId={1}
                licenseName="MIT"
                resourceTypes={mockResourceTypes}
                excludedIds={[1]}
                onExcludedChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button'));

        expect(await screen.findByText('Available for 2 types')).toBeInTheDocument();
    });
});
