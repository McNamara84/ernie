import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import LocationMap from '@/components/landing-pages/shared/LocationMap';

describe('LocationMap', () => {
    const mockResourceWithPoint = {
        coverages: [
            {
                id: 1,
                lat_min: 52.520008,
                lat_max: 52.520008,
                lon_min: 13.404954,
                lon_max: 13.404954,
                description: 'Berlin, Germany',
            },
        ],
    };

    const mockResourceWithBoundingBox = {
        coverages: [
            {
                id: 1,
                lat_min: 47.0,
                lat_max: 55.0,
                lon_min: 5.0,
                lon_max: 15.0,
                start_date: '2020-01-01',
                end_date: '2020-12-31',
                description: 'Central Europe Study Area',
            },
        ],
    };

    const mockResourceWithMultipleCoverages = {
        coverages: [
            {
                id: 1,
                lat_min: 52.5,
                lat_max: 52.5,
                lon_min: 13.4,
                lon_max: 13.4,
                description: 'Berlin',
            },
            {
                id: 2,
                lat_min: 48.8,
                lat_max: 48.8,
                lon_min: 2.3,
                lon_max: 2.3,
                description: 'Paris',
            },
        ],
    };

    const mockResourceNoCoverages = {
        coverages: [],
    };

    const mockResourceInvalidCoordinates = {
        coverages: [
            {
                id: 1,
                lat_min: null,
                lat_max: null,
                lon_min: null,
                lon_max: null,
            },
        ],
    };

    // Mock Google Maps API - Note: Tests run without actual Google Maps loaded
    beforeEach(() => {
        // Ensure Google Maps is not available for testing fallback behavior
        (window as { google?: unknown }).google = undefined as unknown as typeof google;
    });

    describe('Rendering', () => {
        it('should not render when no coverages', () => {
            const { container } = render(<LocationMap resource={mockResourceNoCoverages} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when coverages have invalid coordinates', () => {
            const { container } = render(<LocationMap resource={mockResourceInvalidCoordinates} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should render heading', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            expect(screen.getByRole('heading', { name: /^location$/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(<LocationMap resource={mockResourceWithPoint} heading="Study Area" />);

            expect(screen.getByRole('heading', { name: /study area/i })).toBeInTheDocument();
        });

        it('should show map error message when Google Maps not available', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            expect(screen.getByText(/Map unavailable. Google Maps API not loaded./)).toBeInTheDocument();
        });
    });

    describe('Legend Display', () => {
        it('should display legend by default', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            expect(screen.getByText('Berlin, Germany')).toBeInTheDocument();
        });

        it('should hide legend when showLegend=false', () => {
            render(<LocationMap resource={mockResourceWithPoint} showLegend={false} />);

            expect(screen.queryByText('Berlin, Germany')).not.toBeInTheDocument();
        });

        it('should show point coordinates in legend', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            expect(screen.getByText(/Point:/)).toBeInTheDocument();
            expect(screen.getByText(/52.520008°, 13.404954°/)).toBeInTheDocument();
        });

        it('should show bounding box coordinates in legend', () => {
            render(<LocationMap resource={mockResourceWithBoundingBox} />);

            expect(screen.getByText(/Bounding Box:/)).toBeInTheDocument();
            expect(screen.getByText(/N: 55.0000°/)).toBeInTheDocument();
            expect(screen.getByText(/S: 47.0000°/)).toBeInTheDocument();
        });

        it('should display temporal coverage in legend', () => {
            render(<LocationMap resource={mockResourceWithBoundingBox} />);

            expect(screen.getByText(/From: Jan 1, 2020/)).toBeInTheDocument();
            expect(screen.getByText(/To: Dec 31, 2020/)).toBeInTheDocument();
        });

        it('should display all coverages in legend', () => {
            render(<LocationMap resource={mockResourceWithMultipleCoverages} />);

            expect(screen.getByText('Berlin')).toBeInTheDocument();
            expect(screen.getByText('Paris')).toBeInTheDocument();
        });

        it('should show default name when description is missing', () => {
            const resourceWithoutDescription = {
                coverages: [
                    {
                        lat_min: 50.0,
                        lat_max: 51.0,
                        lon_min: 10.0,
                        lon_max: 11.0,
                    },
                ],
            };

            render(<LocationMap resource={resourceWithoutDescription} />);

            expect(screen.getByText('Coverage 1')).toBeInTheDocument();
        });
    });

    describe('Map Container', () => {
        it('should render map container with correct height', () => {
            render(<LocationMap resource={mockResourceWithPoint} height="500px" />);

            const mapContainer = screen.getByTestId('location-map');
            expect(mapContainer).toHaveStyle({ height: '500px' });
        });

        it('should use default height', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            const mapContainer = screen.getByTestId('location-map');
            expect(mapContainer).toHaveStyle({ height: '400px' });
        });

        it('should have accessibility attributes', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            const mapContainer = screen.getByTestId('location-map');
            expect(mapContainer).toHaveAttribute('role', 'application');
            expect(mapContainer).toHaveAttribute('aria-label');
        });
    });

    describe('Edge Cases', () => {
        it('should handle coverage without ID using index as key', () => {
            const resourceWithoutIds = {
                coverages: [
                    {
                        lat_min: 50.0,
                        lat_max: 50.0,
                        lon_min: 10.0,
                        lon_max: 10.0,
                    },
                ],
            };

            render(<LocationMap resource={resourceWithoutIds} />);

            expect(screen.getByTestId('location-map')).toBeInTheDocument();
        });

        it('should handle missing temporal coverage', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            // Should not show temporal info
            expect(screen.queryByText(/From:/)).not.toBeInTheDocument();
        });

        it('should handle partial coordinates (only lat_min)', () => {
            const resourcePartialCoords = {
                coverages: [
                    {
                        lat_min: 50.0,
                        lat_max: null,
                        lon_min: null,
                        lon_max: null,
                    },
                ],
            };

            const { container } = render(<LocationMap resource={resourcePartialCoords} />);

            // Should not render (invalid coordinates)
            expect(container).toBeEmptyDOMElement();
        });

        it('should handle NaN coordinates', () => {
            const resourceNaNCoords = {
                coverages: [
                    {
                        lat_min: NaN,
                        lat_max: NaN,
                        lon_min: NaN,
                        lon_max: NaN,
                    },
                ],
            };

            const { container } = render(<LocationMap resource={resourceNaNCoords} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should handle extreme coordinates', () => {
            const resourceExtremeCoords = {
                coverages: [
                    {
                        lat_min: -90,
                        lat_max: 90,
                        lon_min: -180,
                        lon_max: 180,
                        description: 'Global Coverage',
                    },
                ],
            };

            render(<LocationMap resource={resourceExtremeCoords} />);

            expect(screen.getByText('Global Coverage')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(<LocationMap resource={mockResourceWithPoint} />);

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Location');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <LocationMap resource={mockResourceWithPoint} heading="Coverage Area" />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Coverage Area');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(<LocationMap resource={mockResourceWithPoint} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });
    });

    describe('Dark Mode Support', () => {
        it('should have dark mode classes', () => {
            const { container } = render(<LocationMap resource={mockResourceWithPoint} />);

            const darkElements = container.querySelectorAll('.dark\\:text-gray-100, .dark\\:bg-gray-800');
            expect(darkElements.length).toBeGreaterThan(0);
        });
    });
});
