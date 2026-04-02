/**
 * @vitest-environment jsdom
 */
import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalDatacenterFilter } from '@/components/portal/PortalDatacenterFilter';
import type { DatacenterFacet } from '@/types/portal';

describe('PortalDatacenterFilter', () => {
    const facets: DatacenterFacet[] = [
        { name: 'GFZ Data Services', count: 42 },
        { name: 'GEOFON', count: 15 },
        { name: 'EPOS', count: 3 },
    ];

    const defaultProps = {
        facets,
        selectedNames: [] as string[],
        onSelectionChange: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('shows "All Datacenters" when none are selected', () => {
            render(<PortalDatacenterFilter {...defaultProps} />);

            expect(screen.getByText('All Datacenters')).toBeInTheDocument();
        });

        it('shows selected count when datacenters are selected', () => {
            render(<PortalDatacenterFilter {...defaultProps} selectedNames={['GFZ Data Services', 'GEOFON']} />);

            expect(screen.getByText('2 selected')).toBeInTheDocument();
        });

        it('shows badge with count when datacenters are selected', () => {
            render(<PortalDatacenterFilter {...defaultProps} selectedNames={['GFZ Data Services']} />);

            expect(screen.getByText('1 selected')).toBeInTheDocument();
            expect(screen.getByText('1')).toBeInTheDocument();
        });
    });

    describe('dropdown interaction', () => {
        it('opens the popover and displays facets when clicked', async () => {
            const user = userEvent.setup();
            render(<PortalDatacenterFilter {...defaultProps} />);

            await user.click(screen.getByText('All Datacenters'));

            expect(screen.getByPlaceholderText('Search datacenters...')).toBeInTheDocument();
            expect(screen.getByText('GFZ Data Services')).toBeInTheDocument();
            expect(screen.getByText('GEOFON')).toBeInTheDocument();
            expect(screen.getByText('EPOS')).toBeInTheDocument();
        });

        it('displays facet counts next to each datacenter', async () => {
            const user = userEvent.setup();
            render(<PortalDatacenterFilter {...defaultProps} />);

            await user.click(screen.getByText('All Datacenters'));

            expect(screen.getByText('42')).toBeInTheDocument();
            expect(screen.getByText('15')).toBeInTheDocument();
            expect(screen.getByText('3')).toBeInTheDocument();
        });

        it('calls onSelectionChange with the toggled datacenter when an item is selected', async () => {
            const user = userEvent.setup();
            const onSelectionChange = vi.fn();

            render(<PortalDatacenterFilter {...defaultProps} onSelectionChange={onSelectionChange} />);

            await user.click(screen.getByText('All Datacenters'));
            await user.click(screen.getByText('GFZ Data Services'));

            expect(onSelectionChange).toHaveBeenCalledWith(['GFZ Data Services']);
        });

        it('calls onSelectionChange without the datacenter when a selected item is deselected', async () => {
            const user = userEvent.setup();
            const onSelectionChange = vi.fn();

            render(
                <PortalDatacenterFilter
                    {...defaultProps}
                    selectedNames={['GFZ Data Services', 'GEOFON']}
                    onSelectionChange={onSelectionChange}
                />,
            );

            await user.click(screen.getByText('2 selected'));
            await user.click(screen.getByText('GFZ Data Services'));

            expect(onSelectionChange).toHaveBeenCalledWith(['GEOFON']);
        });

        it('shows "No datacenters found." when facets are empty', async () => {
            const user = userEvent.setup();
            render(<PortalDatacenterFilter {...defaultProps} facets={[]} />);

            await user.click(screen.getByText('All Datacenters'));

            expect(screen.getByText('No datacenters found.')).toBeInTheDocument();
        });
    });

    describe('clear filter', () => {
        it('does not show "Clear filter" button when no selection', async () => {
            const user = userEvent.setup();
            render(<PortalDatacenterFilter {...defaultProps} />);

            await user.click(screen.getByText('All Datacenters'));

            expect(screen.queryByText('Clear filter')).not.toBeInTheDocument();
        });

        it('shows "Clear filter" button when datacenters are selected', async () => {
            const user = userEvent.setup();
            render(<PortalDatacenterFilter {...defaultProps} selectedNames={['GFZ Data Services']} />);

            await user.click(screen.getByText('1 selected'));

            expect(screen.getByText('Clear filter')).toBeInTheDocument();
        });

        it('calls onSelectionChange with empty array when "Clear filter" is clicked', async () => {
            const user = userEvent.setup();
            const onSelectionChange = vi.fn();

            render(
                <PortalDatacenterFilter
                    {...defaultProps}
                    selectedNames={['GFZ Data Services']}
                    onSelectionChange={onSelectionChange}
                />,
            );

            await user.click(screen.getByText('1 selected'));
            await user.click(screen.getByText('Clear filter'));

            expect(onSelectionChange).toHaveBeenCalledWith([]);
        });
    });
});
