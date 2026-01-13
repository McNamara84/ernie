import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

// Note: MapPicker requires Google Maps API which is difficult to mock in unit tests
// These tests focus on the component logic rather than actual map interactions
// For full map testing, use Playwright E2E tests

// Mock Google Maps API components
const mockMap = {
    setOptions: vi.fn(),
    panTo: vi.fn(),
    fitBounds: vi.fn(),
    setZoom: vi.fn(),
    addListener: vi.fn(function() { return { remove: vi.fn() }; }),
    get: vi.fn(function(key: string) {
        // Return default values for map properties
        if (key === 'draggable') return true;
        return undefined;
    }),
};

vi.mock('@vis.gl/react-google-maps', () => ({
    APIProvider: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    Map: ({ onClick, children }: { onClick: (e: unknown) => void; children: React.ReactNode }) => (
        <div data-testid="google-map">
            {children}
            <button
                onClick={() =>
                    onClick({
                        detail: {
                            latLng: { lat: 48.137154, lng: 11.576124 },
                        },
                    })
                }
                data-testid="map-click-trigger"
            >
                Click Map
            </button>
        </div>
    ),
    AdvancedMarker: ({ position }: { position: { lat: number; lng: number } }) => (
        <div data-testid="map-marker">
            Marker at {position.lat}, {position.lng}
        </div>
    ),
    useMap: () => mockMap,
}));

// Mock Google Maps global objects
const mockGeocoderResult = {
    results: [
        {
            geometry: {
                location: {
                    lat: () => 48.137154,
                    lng: () => 11.576124,
                },
            },
        },
    ],
};

class MockGeocoder {
    geocode() {
        return Promise.resolve(mockGeocoderResult);
    }
}

// Create a mock Rectangle class that can be spied on
const MockRectangle = vi.fn(function MockRectangle() {
    return {
        setMap: vi.fn(),
        setBounds: vi.fn(),
        getBounds: function() {
            return {
                north: 48.2,
                south: 48.1,
                east: 11.7,
                west: 11.5,
            };
        },
    };
});

global.google = {
    maps: {
        Rectangle: MockRectangle,
        Geocoder: MockGeocoder,
    },
// eslint-disable-next-line @typescript-eslint/no-explicit-any
} as any;

import MapPicker from '@/components/curation/fields/spatial-temporal-coverage/MapPicker';

describe('MapPicker', () => {
    const mockOnPointSelected = vi.fn();
    const mockOnRectangleSelected = vi.fn();

    const defaultProps = {
        apiKey: 'test-api-key',
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        onPointSelected: mockOnPointSelected,
        onRectangleSelected: mockOnRectangleSelected,
    };

    beforeEach(() => {
        mockOnPointSelected.mockClear();
        mockOnRectangleSelected.mockClear();
        mockMap.setOptions.mockClear();
        mockMap.panTo.mockClear();
        mockMap.fitBounds.mockClear();
        mockMap.setZoom.mockClear();
        mockMap.addListener.mockClear();
        vi.clearAllMocks();
    });

    describe('Rendering', () => {
        test('renders map picker component', () => {
            render(<MapPicker {...defaultProps} />);

            expect(screen.getByText(/map picker/i)).toBeInTheDocument();
            expect(screen.getByTestId('google-map')).toBeInTheDocument();
        });

        test('renders search input', () => {
            render(<MapPicker {...defaultProps} />);

            expect(screen.getByPlaceholderText(/search for a location/i)).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /search/i })).toBeInTheDocument();
        });

        test('renders drawing tool buttons', () => {
            render(<MapPicker {...defaultProps} />);

            expect(screen.getByRole('button', { name: /point/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /rectangle/i })).toBeInTheDocument();
        });

        test('renders fullscreen button', () => {
            render(<MapPicker {...defaultProps} />);

            expect(screen.getByRole('button', { name: /fullscreen/i })).toBeInTheDocument();
        });
    });

    describe('Drawing Modes', () => {
        test('activates point mode when point button is clicked', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const pointButton = screen.getByRole('button', { name: /point/i });
            await user.click(pointButton);

            expect(screen.getByText(/click on the map to place a marker/i)).toBeInTheDocument();
        });

        test('activates rectangle mode when rectangle button is clicked', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const rectangleButton = screen.getByRole('button', { name: /rectangle/i });
            await user.click(rectangleButton);

            expect(screen.getByText(/click and drag to draw rectangle/i)).toBeInTheDocument();
        });

        test('deactivates mode when clicking active mode button again', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const pointButton = screen.getByRole('button', { name: /point/i });
            await user.click(pointButton);
            expect(screen.getByText(/click on the map/i)).toBeInTheDocument();

            await user.click(pointButton);
            expect(screen.queryByText(/click on the map/i)).not.toBeInTheDocument();
        });

        test('sets crosshair cursor when rectangle mode is active', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const rectangleButton = screen.getByRole('button', { name: /rectangle/i });
            await user.click(rectangleButton);

            await waitFor(() => {
                expect(mockMap.setOptions).toHaveBeenCalledWith(
                    expect.objectContaining({ draggableCursor: 'crosshair' })
                );
            });
        });
    });

    describe('Search Functionality', () => {
        test('can enter search query', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const searchInput = screen.getByPlaceholderText(/search for a location/i);
            await user.type(searchInput, 'Munich, Germany');

            expect(searchInput).toHaveValue('Munich, Germany');
        });

        test('searches when search button is clicked', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const searchInput = screen.getByPlaceholderText(/search for a location/i);
            await user.type(searchInput, 'Munich');

            const searchButton = screen.getByRole('button', { name: /search/i });
            await user.click(searchButton);

            await waitFor(() => {
                expect(mockMap.panTo).toHaveBeenCalled();
                expect(mockMap.setZoom).toHaveBeenCalledWith(12);
            });
        });

        test('searches when Enter key is pressed', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const searchInput = screen.getByPlaceholderText(/search for a location/i);
            await user.type(searchInput, 'Munich{Enter}');

            await waitFor(() => {
                expect(mockMap.panTo).toHaveBeenCalled();
            });
        });

        test('does not search with empty query', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const searchButton = screen.getByRole('button', { name: /search/i });
            await user.click(searchButton);

            expect(mockMap.panTo).not.toHaveBeenCalled();
        });
    });

    describe('Fullscreen Mode', () => {
        test('opens fullscreen dialog when fullscreen button is clicked', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const fullscreenButton = screen.getByRole('button', { name: /fullscreen/i });
            await user.click(fullscreenButton);

            expect(screen.getByRole('dialog')).toBeInTheDocument();
            expect(screen.getByText(/map picker - fullscreen/i)).toBeInTheDocument();
        });

        test('closes fullscreen dialog when escape is pressed', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            const fullscreenButton = screen.getByRole('button', { name: /fullscreen/i });
            await user.click(fullscreenButton);

            expect(screen.getByRole('dialog')).toBeInTheDocument();

            await user.keyboard('{Escape}');

            await waitFor(() => {
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            });
        });
    });

    describe('Existing Coordinates Display', () => {
        test('displays marker when point coordinates are provided', () => {
            const props = {
                ...defaultProps,
                latMin: '48.137154',
                lonMin: '11.576124',
            };

            render(<MapPicker {...props} />);

            expect(screen.getByTestId('map-marker')).toBeInTheDocument();
        });

        test('does not display marker when coordinates are invalid', () => {
            const props = {
                ...defaultProps,
                latMin: 'invalid',
                lonMin: 'invalid',
            };

            render(<MapPicker {...props} />);

            expect(screen.queryByTestId('map-marker')).not.toBeInTheDocument();
        });

        test('initializes rectangle when all coordinates are provided', () => {
            const props = {
                ...defaultProps,
                latMin: '48.1',
                lonMin: '11.5',
                latMax: '48.2',
                lonMax: '11.7',
            };

            render(<MapPicker {...props} />);

            // Rectangle should be created
            expect(google.maps.Rectangle).toHaveBeenCalled();
        });
    });

    describe('Help Text', () => {
        test('shows help text at the bottom', () => {
            render(<MapPicker {...defaultProps} />);

            expect(
                screen.getByText(/use the drawing tools to select a point or rectangle/i)
            ).toBeInTheDocument();
        });
    });

    describe('Component Props', () => {
        test('uses provided API key', () => {
            const props = {
                ...defaultProps,
                apiKey: 'my-custom-api-key',
            };

            render(<MapPicker {...props} />);

            // Component should render without errors
            expect(screen.getByTestId('google-map')).toBeInTheDocument();
        });

        test('calls onPointSelected callback with correct coordinates', async () => {
            const user = userEvent.setup();
            render(<MapPicker {...defaultProps} />);

            // Activate point mode
            const pointButton = screen.getByRole('button', { name: /point/i });
            await user.click(pointButton);

            // Trigger map click
            const mapClickTrigger = screen.getByTestId('map-click-trigger');
            await user.click(mapClickTrigger);

            expect(mockOnPointSelected).toHaveBeenCalledWith(48.137154, 11.576124);
        });
    });

    describe('Accessibility', () => {
        test('search input has proper placeholder', () => {
            render(<MapPicker {...defaultProps} />);

            const searchInput = screen.getByPlaceholderText(/search for a location/i);
            expect(searchInput).toHaveAttribute('type', 'text');
        });

        test('buttons have accessible labels', () => {
            render(<MapPicker {...defaultProps} />);

            expect(screen.getByRole('button', { name: /point/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /rectangle/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /search/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /fullscreen/i })).toBeInTheDocument();
        });
    });
});
