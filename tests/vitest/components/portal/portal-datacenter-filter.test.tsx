/**
 * @vitest-environment jsdom
 */
import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalDatacenterFilter } from '@/components/portal/PortalDatacenterFilter';
import type { DatacenterFacet } from '@/types/portal';

describe('PortalDatacenterFilter', () => {
    const facets: DatacenterFacet[] = [
        { name: 'EPOS', count: 3 },
        { name: 'GEOFON', count: 15 },
        { name: 'GFZ Data Services', count: 1_234 },
    ];

    const defaultProps = {
        facets,
        selectedNames: [] as string[],
        onSelectionChange: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the search, datacenters, and resource counts inline without another trigger', () => {
        render(<PortalDatacenterFilter {...defaultProps} />);

        expect(screen.getByPlaceholderText('Search datacenters...')).toBeVisible();
        expect(screen.getByRole('group', { name: 'Datacenters' })).toBeVisible();
        expect(screen.getByText('EPOS')).toBeVisible();
        expect(screen.getByText('GEOFON')).toBeVisible();
        expect(screen.getByText('GFZ Data Services')).toBeVisible();
        expect(screen.getByText('3')).toBeVisible();
        expect(screen.getByText('15')).toBeVisible();
        expect(screen.getByText('1,234')).toBeVisible();
        expect(screen.queryByText('All Datacenters')).not.toBeInTheDocument();
    });

    it('preserves the facet order supplied by the backend', () => {
        render(<PortalDatacenterFilter {...defaultProps} />);

        const checkboxes = within(screen.getByRole('group', { name: 'Datacenters' })).getAllByRole('checkbox');

        expect(checkboxes.map((checkbox) => checkbox.getAttribute('aria-label'))).toEqual([
            'Select EPOS',
            'Select GEOFON',
            'Select GFZ Data Services',
        ]);
    });

    it('selects an unchecked datacenter while preserving existing selections', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(<PortalDatacenterFilter {...defaultProps} selectedNames={['EPOS']} onSelectionChange={onSelectionChange} />);

        await user.click(screen.getByRole('checkbox', { name: 'Select GEOFON' }));

        expect(onSelectionChange).toHaveBeenCalledWith(['EPOS', 'GEOFON']);
    });

    it('deselects a checked datacenter from its checkbox', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(<PortalDatacenterFilter {...defaultProps} selectedNames={['EPOS', 'GEOFON']} onSelectionChange={onSelectionChange} />);

        expect(screen.getByRole('checkbox', { name: 'Select EPOS' })).toBeChecked();
        await user.click(screen.getByRole('checkbox', { name: 'Select EPOS' }));

        expect(onSelectionChange).toHaveBeenCalledWith(['GEOFON']);
    });

    it('shows selected datacenters as removable chips', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(<PortalDatacenterFilter {...defaultProps} selectedNames={['EPOS', 'GEOFON']} onSelectionChange={onSelectionChange} />);

        await user.click(screen.getByRole('button', { name: 'Remove GEOFON' }));

        expect(onSelectionChange).toHaveBeenCalledWith(['EPOS']);
    });

    it('filters datacenters through the inline search without changing their selection', async () => {
        const user = userEvent.setup();

        render(<PortalDatacenterFilter {...defaultProps} selectedNames={['EPOS']} />);

        await user.type(screen.getByPlaceholderText('Search datacenters...'), 'geo');

        expect(screen.getByText('GEOFON')).toBeVisible();
        expect(screen.queryByRole('checkbox', { name: 'Select EPOS' })).not.toBeInTheDocument();
        expect(screen.queryByText('GFZ Data Services')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove EPOS' })).toBeVisible();
    });

    it('shows a no-match state for an unsuccessful search', async () => {
        const user = userEvent.setup();

        render(<PortalDatacenterFilter {...defaultProps} />);

        await user.type(screen.getByPlaceholderText('Search datacenters...'), 'missing');

        expect(screen.getByText('No matching values.')).toBeVisible();
        expect(screen.queryByRole('group', { name: 'Datacenters' })).not.toBeInTheDocument();
    });

    it('shows an empty state without rendering a redundant search field', () => {
        render(<PortalDatacenterFilter {...defaultProps} facets={[]} />);

        expect(screen.getByText('No datacenters available.')).toBeVisible();
        expect(screen.queryByPlaceholderText('Search datacenters...')).not.toBeInTheDocument();
    });

    it('keeps a selected URL value removable when it is absent from the available facets', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();

        render(<PortalDatacenterFilter facets={[]} selectedNames={['Missing datacenter']} onSelectionChange={onSelectionChange} />);

        await user.click(screen.getByRole('button', { name: 'Remove Missing datacenter' }));

        expect(onSelectionChange).toHaveBeenCalledWith([]);
    });

    it('keeps a long selected value absent from the facets readable and keyboard removable', async () => {
        const user = userEvent.setup();
        const onSelectionChange = vi.fn();
        const longName = 'DatacenterWithAnUnbrokenIdentifierABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        render(<PortalDatacenterFilter facets={[]} selectedNames={[longName]} onSelectionChange={onSelectionChange} />);

        expect(screen.getByText(longName)).toBeVisible();
        await user.tab();
        expect(screen.getByRole('button', { name: `Remove ${longName}` })).toHaveFocus();
        await user.keyboard('{Enter}');

        expect(onSelectionChange).toHaveBeenCalledWith([]);
    });

    it('describes the unchanged OR semantics', () => {
        render(<PortalDatacenterFilter {...defaultProps} />);

        expect(screen.getByText('A result may match any selected datacenter.')).toBeVisible();
    });
});
