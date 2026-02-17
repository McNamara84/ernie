import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

// Mock react-leaflet
vi.mock('react-leaflet', () => ({
    MapContainer: ({ children, className }: { children: React.ReactNode; className?: string }) => (
        <div data-testid="map-container" className={className}>
            {children}
        </div>
    ),
    TileLayer: () => <div data-testid="tile-layer" />,
    Marker: ({ children }: { children?: React.ReactNode }) => <div data-testid="map-marker">{children}</div>,
    Popup: ({ children }: { children?: React.ReactNode }) => <div data-testid="map-popup">{children}</div>,
    useMap: () => ({
        fitBounds: vi.fn(),
    }),
}));

// Mock leaflet
vi.mock('leaflet', () => ({
    default: {
        Icon: {
            Default: {
                prototype: {},
                mergeOptions: vi.fn(),
            },
        },
        latLngBounds: vi.fn((points: unknown[]) => ({
            isValid: () => points && points.length > 0,
        })),
    },
}));

vi.mock('leaflet/dist/leaflet.css', () => ({}));
vi.mock('leaflet/dist/images/marker-icon.png', () => ({ default: 'marker-icon.png' }));
vi.mock('leaflet/dist/images/marker-icon-2x.png', () => ({ default: 'marker-icon-2x.png' }));
vi.mock('leaflet/dist/images/marker-shadow.png', () => ({ default: 'marker-shadow.png' }));

import IgsnMapPage from '@/pages/igsns/map';

function createIgsnMapItem(
    id: number,
    geoLocations: Array<{ id: number; latitude: number; longitude: number; place: string | null }> = [],
) {
    return {
        id,
        igsn: `IGSN:10273/TEST${id.toString().padStart(3, '0')}`,
        title: `Sample ${id}`,
        creator: `Researcher ${id}`,
        publication_year: 2024,
        geoLocations,
    };
}

describe('IgsnMapPage', () => {
    it('renders within AppLayout', () => {
        render(<IgsnMapPage igsns={[]} />);
        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
    });

    it('renders the map heading', () => {
        render(<IgsnMapPage igsns={[]} />);
        expect(screen.getByText('IGSNs Map')).toBeInTheDocument();
    });

    it('renders MapContainer', () => {
        render(<IgsnMapPage igsns={[]} />);
        expect(screen.getByTestId('map-container')).toBeInTheDocument();
    });

    it('shows location count for empty list', () => {
        render(<IgsnMapPage igsns={[]} />);
        expect(screen.getByText(/0 locations from 0 IGSNs/)).toBeInTheDocument();
    });

    it('renders markers for IGSNs with geo locations', () => {
        const igsns = [
            createIgsnMapItem(1, [{ id: 1, latitude: 52.38, longitude: 13.06, place: 'Berlin' }]),
            createIgsnMapItem(2, [{ id: 2, latitude: 48.86, longitude: 2.35, place: 'Paris' }]),
        ];
        render(<IgsnMapPage igsns={igsns} />);
        const markers = screen.getAllByTestId('map-marker');
        expect(markers).toHaveLength(2);
    });

    it('shows correct count for single IGSN', () => {
        const igsns = [
            createIgsnMapItem(1, [{ id: 1, latitude: 52.38, longitude: 13.06, place: 'Berlin' }]),
        ];
        render(<IgsnMapPage igsns={igsns} />);
        expect(screen.getByText(/1 location from 1 IGSN/)).toBeInTheDocument();
    });

    it('shows plural counts for multiple IGSNs with multiple locations', () => {
        const igsns = [
            createIgsnMapItem(1, [
                { id: 1, latitude: 52.38, longitude: 13.06, place: 'Berlin' },
                { id: 2, latitude: 48.86, longitude: 2.35, place: 'Paris' },
            ]),
            createIgsnMapItem(2, [{ id: 3, latitude: 40.71, longitude: -74.01, place: 'New York' }]),
        ];
        render(<IgsnMapPage igsns={igsns} />);
        expect(screen.getByText(/3 locations from 2 IGSNs/)).toBeInTheDocument();
    });

    it('renders popup content with IGSN title', () => {
        const igsns = [
            createIgsnMapItem(1, [{ id: 1, latitude: 52.38, longitude: 13.06, place: 'Berlin' }]),
        ];
        render(<IgsnMapPage igsns={igsns} />);
        expect(screen.getByText('Sample 1')).toBeInTheDocument();
    });
});
