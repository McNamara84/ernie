import { createMockPortalResource } from '@test-helpers/factories';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { PortalMapLegend } from '@/components/portal/PortalMapLegend';
import type { PortalMapFeature, PortalResource } from '@/types/portal';

function makeResource(overrides?: Partial<PortalResource>): PortalResource {
    return createMockPortalResource(overrides);
}

describe('PortalMapLegend', () => {
    it('renders nothing when resources array is empty', () => {
        const { container } = render(<PortalMapLegend resources={[]} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders nothing when no resources have geo locations', () => {
        // Legend receives resourcesWithGeo from parent — if parent passes empty,
        // legend should still not render
        const { container } = render(<PortalMapLegend resources={[]} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders the legend container with correct testid', () => {
        const resources = [makeResource({ resourceTypeSlug: 'dataset', resourceType: 'Dataset' })];
        render(<PortalMapLegend resources={resources} />);
        expect(screen.getByTestId('portal-map-legend')).toBeInTheDocument();
    });

    it('displays "Resource Types" header', () => {
        const resources = [makeResource({ resourceTypeSlug: 'dataset', resourceType: 'Dataset' })];
        render(<PortalMapLegend resources={resources} />);
        expect(screen.getByText('Resource Types')).toBeInTheDocument();
    });

    it('shows one legend entry per unique resource type', () => {
        const resources = [
            makeResource({ id: 1, resourceTypeSlug: 'dataset', resourceType: 'Dataset' }),
            makeResource({ id: 2, resourceTypeSlug: 'dataset', resourceType: 'Dataset' }),
            makeResource({ id: 3, resourceTypeSlug: 'software', resourceType: 'Software' }),
        ];
        render(<PortalMapLegend resources={resources} />);
        expect(screen.getByText('Dataset')).toBeInTheDocument();
        expect(screen.getByText('Software')).toBeInTheDocument();
        expect(screen.getByTestId('legend-item-dataset')).toBeInTheDocument();
        expect(screen.getByTestId('legend-item-software')).toBeInTheDocument();
    });

    it('does not duplicate entries for the same resource type', () => {
        const resources = [
            makeResource({ id: 1, resourceTypeSlug: 'dataset', resourceType: 'Dataset' }),
            makeResource({ id: 2, resourceTypeSlug: 'dataset', resourceType: 'Dataset' }),
        ];
        render(<PortalMapLegend resources={resources} />);
        const items = screen.getAllByText('Dataset');
        expect(items).toHaveLength(1);
    });

    it('sorts IGSN (physical-object) to the end', () => {
        const resources = [
            makeResource({ id: 1, resourceTypeSlug: 'physical-object', resourceType: 'IGSN Samples', isIgsn: true }),
            makeResource({ id: 2, resourceTypeSlug: 'dataset', resourceType: 'Dataset' }),
            makeResource({ id: 3, resourceTypeSlug: 'software', resourceType: 'Software' }),
        ];
        render(<PortalMapLegend resources={resources} />);

        const legend = screen.getByTestId('portal-map-legend');
        const items = legend.querySelectorAll('[data-testid^="legend-item-"]');
        const slugs = Array.from(items).map((el) => el.getAttribute('data-testid')?.replace('legend-item-', ''));

        // physical-object should be last
        expect(slugs[slugs.length - 1]).toBe('physical-object');
    });

    it('renders a diamond shape for IGSN type', () => {
        const resources = [makeResource({ resourceTypeSlug: 'physical-object', resourceType: 'IGSN Samples', isIgsn: true })];
        render(<PortalMapLegend resources={resources} />);

        const item = screen.getByTestId('legend-item-physical-object');
        const swatch = item.querySelector('span');
        expect(swatch?.className).toContain('rotate-45');
    });

    it('renders a circle shape for non-IGSN types', () => {
        const resources = [makeResource({ resourceTypeSlug: 'dataset', resourceType: 'Dataset' })];
        render(<PortalMapLegend resources={resources} />);

        const item = screen.getByTestId('legend-item-dataset');
        const swatch = item.querySelector('span');
        expect(swatch?.className).toContain('rounded-full');
        expect(swatch?.className).not.toContain('rotate-45');
    });

    it('applies correct background color to legend swatches', () => {
        const resources = [
            makeResource({ resourceTypeSlug: 'dataset', resourceType: 'Dataset' }),
            makeResource({ id: 2, resourceTypeSlug: 'software', resourceType: 'Software' }),
        ];
        render(<PortalMapLegend resources={resources} />);

        const datasetItem = screen.getByTestId('legend-item-dataset');
        const datasetSwatch = datasetItem.querySelector('span');
        // jsdom normalizes hex colors to rgb()
        expect(datasetSwatch?.style.backgroundColor).toBeTruthy();

        const softwareItem = screen.getByTestId('legend-item-software');
        const softwareSwatch = softwareItem.querySelector('span');
        expect(softwareSwatch?.style.backgroundColor).toBeTruthy();

        // Ensure different types get different colors
        expect(datasetSwatch?.style.backgroundColor).not.toBe(softwareSwatch?.style.backgroundColor);
    });

    it('falls back to "other" slug when resourceTypeSlug is null', () => {
        const resources = [makeResource({ resourceTypeSlug: null, resourceType: 'Unknown' })];
        render(<PortalMapLegend resources={resources} />);
        expect(screen.getByTestId('legend-item-other')).toBeInTheDocument();
        expect(screen.getByText('Unknown')).toBeInTheDocument();
    });

    it('updates legend when resources change', () => {
        const initial = [makeResource({ id: 1, resourceTypeSlug: 'dataset', resourceType: 'Dataset' })];
        const { rerender } = render(<PortalMapLegend resources={initial} />);
        expect(screen.getByText('Dataset')).toBeInTheDocument();
        expect(screen.queryByText('Software')).not.toBeInTheDocument();

        const updated = [makeResource({ id: 2, resourceTypeSlug: 'software', resourceType: 'Software' })];
        rerender(<PortalMapLegend resources={updated} />);
        expect(screen.getByText('Software')).toBeInTheDocument();
        expect(screen.queryByText('Dataset')).not.toBeInTheDocument();
    });

    it('renders material categories and location counts for an IGSN composition', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'cluster',
                id: 'z2:1:1',
                position: { lat: 1, lng: 1 },
                bounds: { north: 2, south: 0, east: 2, west: 0 },
                count: 10,
                resourceTypeCounts: { 'physical-object': 10 },
                composition: {
                    dimension: 'material',
                    counts: { rock: 6, liquid: 3, missing: 1 },
                },
            },
        ];

        render(<PortalMapLegend features={features} />);

        expect(screen.getByText('Material')).toBeInTheDocument();
        expect(screen.queryByText('Resource Types')).not.toBeInTheDocument();
        expect(screen.getByText('Rock')).toBeInTheDocument();
        expect(screen.getByText('Liquid')).toBeInTheDocument();
        expect(screen.getByText('No material provided')).toBeInTheDocument();
        expect(screen.getByLabelText('6 locations')).toBeInTheDocument();
        expect(screen.getByLabelText('3 locations')).toBeInTheDocument();
        expect(screen.getByLabelText('1 location')).toBeInTheDocument();
    });

    it('orders material categories by the stable palette rather than count or alphabetically', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'cluster',
                id: 'z2:1:1',
                position: { lat: 1, lng: 1 },
                bounds: { north: 2, south: 0, east: 2, west: 0 },
                count: 3,
                resourceTypeCounts: { 'physical-object': 3 },
                composition: {
                    dimension: 'material',
                    counts: { sediment: 1, liquid: 1, rock: 1 },
                },
            },
        ];

        render(<PortalMapLegend features={features} />);
        const items = screen.getByTestId('portal-map-legend').querySelectorAll('[data-testid^="legend-item-"]');
        expect(Array.from(items).map((item) => item.getAttribute('data-testid'))).toEqual([
            'legend-item-rock',
            'legend-item-liquid',
            'legend-item-sediment',
        ]);
    });

    it('distinguishes missing and not-applicable material without color alone', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'cluster',
                id: 'z2:1:1',
                position: { lat: 1, lng: 1 },
                bounds: { north: 2, south: 0, east: 2, west: 0 },
                count: 2,
                resourceTypeCounts: { 'physical-object': 2 },
                composition: {
                    dimension: 'material',
                    counts: { missing: 1, 'not-applicable': 1 },
                },
            },
        ];

        render(<PortalMapLegend features={features} />);
        expect(screen.getByLabelText('No material provided map color')).toHaveStyle({ borderStyle: 'dashed' });
        expect(screen.getByTestId('legend-item-not-applicable').querySelectorAll('span')).toHaveLength(4);
    });

    it('falls back to version-one resource type counts', () => {
        const features: PortalMapFeature[] = [
            {
                kind: 'cluster',
                id: 'z2:1:1',
                position: { lat: 1, lng: 1 },
                bounds: { north: 2, south: 0, east: 2, west: 0 },
                count: 2,
                resourceTypeCounts: { dataset: 2 },
            },
        ];

        render(<PortalMapLegend features={features} />);
        expect(screen.getByText('Resource Types')).toBeInTheDocument();
        expect(screen.getByText('Dataset')).toBeInTheDocument();
    });
});
