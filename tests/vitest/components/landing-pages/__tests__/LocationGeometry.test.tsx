/**
 * @vitest-environment jsdom
 */
import { act, fireEvent, render, screen } from '@tests/vitest/utils/render';
import L from 'leaflet';
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import type { GeoLocation } from '@/lib/landing-page-geolocation';
import { LocationSection } from '@/pages/LandingPages/components/LocationSection';

const base: GeoLocation = {
    id: 1,
    place: 'Coverage description',
    geo_type: 'point',
    point_latitude: null,
    point_longitude: null,
    west_bound_longitude: null,
    east_bound_longitude: null,
    south_bound_latitude: null,
    north_bound_latitude: null,
    polygon_points: null,
};
const geometries: Record<string, GeoLocation> = {
    point: { ...base, point_latitude: 10, point_longitude: 10 },
    box: { ...base, geo_type: 'box', west_bound_longitude: -10, east_bound_longitude: 10, south_bound_latitude: -10, north_bound_latitude: 10 },
    polygon: {
        ...base,
        geo_type: 'polygon',
        polygon_points: [
            { latitude: 0, longitude: 0 },
            { latitude: 10, longitude: 10 },
            { latitude: 0, longitude: 10 },
        ],
    },
    line: {
        ...base,
        geo_type: 'line',
        polygon_points: [
            { latitude: 0, longitude: 0 },
            { latitude: 10, longitude: 10 },
            { latitude: 0, longitude: 20 },
        ],
    },
};
const originalSvg = L.Browser.svg;

beforeAll(() => {
    // jsdom has SVG elements but omits createSVGRect, which Leaflet uses for feature detection.
    Object.defineProperty(L.Browser, 'svg', { value: true, configurable: true, writable: true });
});
afterAll(() => {
    Object.defineProperty(L.Browser, 'svg', { value: originalSvg });
});
beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(L.Map.prototype, 'getSize').mockReturnValue(L.point(400, 400));
});

function object(container: HTMLElement, kind: string): Element {
    return container.querySelector(kind === 'point' ? '.leaflet-marker-icon' : '.leaflet-overlay-pane path')!;
}
function popup(container: HTMLElement): HTMLElement | null {
    return container.querySelector('.leaflet-popup-content');
}

describe('coverage descriptions with real Leaflet layers', () => {
    it.each(Object.entries(geometries))('opens the complete description for a %s and closes it manually', (kind, geo) => {
        const { container } = render(<LocationSection geoLocations={[geo]} />);
        expect(popup(container)).toBeNull();
        fireEvent.click(object(container, kind));
        expect(popup(container)).toHaveTextContent(geo.place!);
        fireEvent.click(screen.getByRole('button', { name: 'Close popup' }));
        expect(popup(container)).toBeNull();
        fireEvent.click(object(container, kind));
        expect(popup(container)).toHaveTextContent(geo.place!);
        fireEvent.click(container.querySelector('.leaflet-container')!);
        expect(popup(container)).toBeNull();
        fireEvent.click(object(container, kind));
        fireEvent.keyDown(object(container, kind), { key: 'Escape' });
        expect(popup(container)).toBeNull();
    });

    it.each(Object.entries(geometries).flatMap(([kind, geo]) => [null, '', ' \n\t '].map((place) => ({ kind, geo: { ...geo, place }, place }))))(
        'keeps $kind visible without click or keyboard activation for description $place',
        ({ kind, geo }) => {
            const { container } = render(<LocationSection geoLocations={[geo]} />);
            const element = object(container, kind);
            expect(element).toBeInTheDocument();
            expect(element).not.toHaveClass('leaflet-interactive');
            expect(element).not.toHaveAttribute('tabindex');
            expect(element).not.toHaveAttribute('role', 'button');
            fireEvent.click(element);
            fireEvent.keyDown(element, { key: 'Enter' });
            expect(popup(container)).toBeNull();
        },
    );

    it.each(['box', 'polygon', 'line'])('opens a %s with Enter and Space, ignores other keys and cleans up listeners', (kind) => {
        const { container, unmount } = render(<LocationSection geoLocations={[geometries[kind]]} />);
        const element = object(container, kind);
        expect(element).toHaveAttribute('role', 'button');
        expect(element).toHaveAttribute('tabindex', '0');
        expect(element.getAttribute('aria-label')).toContain('Show coverage description');
        fireEvent.keyDown(element, { key: 'ArrowRight' });
        expect(popup(container)).toBeNull();
        for (const key of ['Enter', ' ']) {
            expect(fireEvent.keyDown(element, { key })).toBe(false);
            expect(popup(container)).toHaveTextContent(base.place!);
            fireEvent.click(screen.getByRole('button', { name: 'Close popup' }));
        }
        const open = vi.spyOn(L.Layer.prototype, 'openPopup');
        unmount();
        fireEvent.keyDown(element, { key: 'Enter' });
        expect(open).not.toHaveBeenCalled();
        expect(element).not.toHaveAttribute('role');
    });

    it('keeps native marker keyboard activation', () => {
        const { container } = render(<LocationSection geoLocations={[geometries.point]} />);
        const marker = object(container, 'point');
        expect(marker).toHaveAttribute('tabindex', '0');
        fireEvent.keyPress(marker, { key: 'Enter', charCode: 13, keyCode: 13 });
        expect(popup(container)).toHaveTextContent(base.place!);
    });

    it('closes with Escape while a popup control has focus and ignores Escape when closed', () => {
        const { container, unmount } = render(<LocationSection geoLocations={[geometries.box]} />);
        const map = container.querySelector<HTMLElement>('.leaflet-container')!;
        expect(fireEvent.keyDown(map, { key: 'Escape' })).toBe(true);
        fireEvent.click(object(container, 'box'));
        const close = screen.getByRole('button', { name: 'Close popup' });
        close.focus();
        fireEvent.keyDown(close, { key: 'ArrowRight' });
        expect(popup(container)).toBeInTheDocument();
        expect(fireEvent.keyDown(close, { key: 'Escape' })).toBe(false);
        expect(popup(container)).toBeNull();
        const removeListener = vi.spyOn(map, 'removeEventListener');
        unmount();
        expect(removeListener).toHaveBeenCalledWith('keydown', expect.any(Function));
    });

    it('keeps an open description when the fullscreen control is clicked', () => {
        const original = Object.getOwnPropertyDescriptor(document.documentElement, 'requestFullscreen');
        const requestFullscreen = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(document.documentElement, 'requestFullscreen', { configurable: true, value: requestFullscreen });
        try {
            const { container } = render(<LocationSection geoLocations={[geometries.box]} />);
            const map = container.querySelector<HTMLElement>('.leaflet-container')!;
            Object.defineProperty(map, 'requestFullscreen', { configurable: true, value: requestFullscreen });
            fireEvent.click(object(container, 'box'));
            fireEvent.click(screen.getByRole('button', { name: 'Fullscreen' }));
            expect(requestFullscreen).toHaveBeenCalledOnce();
            expect(popup(container)).toHaveTextContent(base.place!);
        } finally {
            if (original) Object.defineProperty(document.documentElement, 'requestFullscreen', original);
            else Reflect.deleteProperty(document.documentElement, 'requestFullscreen');
        }
    });

    it('shows each entry separately and opens only one popup at a time', () => {
        const { container } = render(<LocationSection geoLocations={[geometries.box, { ...geometries.polygon, id: 2, place: 'Other coverage' }]} />);
        const paths = container.querySelectorAll('.leaflet-overlay-pane path');
        fireEvent.click(paths[0]);
        expect(popup(container)).toHaveTextContent(base.place!);
        fireEvent.click(paths[1]);
        expect(popup(container)).toHaveTextContent('Other coverage');
        expect(container.querySelectorAll('.leaflet-popup')).toHaveLength(1);
        expect(popup(container)).not.toHaveTextContent(base.place!);
    });

    it('binds the same description to every geometry of a historical entry', () => {
        const { container } = render(
            <LocationSection
                geoLocations={[
                    {
                        ...geometries.box,
                        ...geometries.polygon,
                        point_latitude: 10,
                        point_longitude: 10,
                        west_bound_longitude: -10,
                        east_bound_longitude: 10,
                        south_bound_latitude: -10,
                        north_bound_latitude: 10,
                    },
                ]}
            />,
        );
        const elements = container.querySelectorAll('.leaflet-marker-icon, .leaflet-overlay-pane path');
        expect(elements).toHaveLength(3);
        elements.forEach((element) => {
            fireEvent.click(element);
            expect(popup(container)).toHaveTextContent(base.place!);
        });
    });

    it('preserves multiline text and escapes markup without truncating long descriptions', () => {
        const description = 'München & Küste\n<img src=x onerror=alert(1)>\n' + 'longword'.repeat(300);
        const { container } = render(<LocationSection geoLocations={[{ ...geometries.box, place: description }]} />);
        fireEvent.click(object(container, 'box'));
        expect(popup(container)?.textContent).toBe(description);
        expect(popup(container)?.querySelector('img')).toBeNull();
        expect(popup(container)?.firstElementChild).toHaveClass('whitespace-pre-wrap', '[overflow-wrap:anywhere]');
    });

    it.each(['combined', 'map'] as const)('opens descriptions in the %s card', (content) => {
        const { container } = render(<LocationSection geoLocations={[geometries.box]} content={content} samplingLocation />);
        fireEvent.click(object(container, 'box'));
        expect(popup(container)).toHaveTextContent(base.place!);
    });

    it('keeps sampling details separate and never uses IGSN descriptions as a fallback', () => {
        const geo = { ...geometries.box, place: null, location_description: 'IGSN location', locality_description: 'IGSN locality' };
        const { container, rerender } = render(<LocationSection geoLocations={[geo]} samplingLocation content="details" />);
        expect(screen.getByText('IGSN location')).toBeInTheDocument();
        expect(container.querySelector('.leaflet-container')).toBeNull();
        rerender(<LocationSection geoLocations={[geo]} content="map" />);
        fireEvent.click(object(container, 'box'));
        expect(popup(container)).toBeNull();
    });

    it('adds, updates and removes descriptions on rerender without stale interaction', () => {
        const { container, rerender } = render(<LocationSection geoLocations={[{ ...geometries.box, place: null }]} />);
        rerender(<LocationSection geoLocations={[geometries.box]} />);
        fireEvent.click(object(container, 'box'));
        expect(popup(container)).toHaveTextContent(base.place!);
        rerender(<LocationSection geoLocations={[{ ...geometries.box, place: 'Updated description' }]} />);
        expect(popup(container)).toBeNull();
        fireEvent.click(object(container, 'box'));
        expect(popup(container)).toHaveTextContent('Updated description');
        rerender(<LocationSection geoLocations={[{ ...geometries.box, place: null }]} />);
        expect(popup(container)).toBeNull();
        expect(object(container, 'box')).not.toHaveAttribute('role');
        fireEvent.click(object(container, 'box'));
        expect(popup(container)).toBeNull();
    });

    it('resizes popups to the map and unregisters resize listeners when removed', () => {
        const bind = vi.spyOn(L.Layer.prototype, 'bindPopup');
        const on = vi.spyOn(L.Map.prototype, 'on');
        const off = vi.spyOn(L.Map.prototype, 'off');
        const { container, unmount } = render(<LocationSection geoLocations={[geometries.box]} />);
        fireEvent.click(object(container, 'box'));
        const opened = screen.getByRole('button', { name: 'Close popup' });
        expect(opened).toBeInTheDocument();
        vi.mocked(L.Map.prototype.getSize).mockReturnValue(L.point(200, 200));
        // Public map instances are available through the resize listener's context.
        const registration = on.mock.calls.findIndex(([event]) => String(event) === 'resize');
        const map = on.mock.contexts[registration] as L.Map;
        const listener = on.mock.calls[registration][1];
        act(() => {
            map.fire('resize', { newSize: L.point(200, 200), oldSize: L.point(400, 400) });
        });
        const boundPopup = bind.mock.calls[0][0] as L.Popup;
        expect(boundPopup.options.maxWidth).toBe(80);
        expect(boundPopup.options.maxHeight).toBe(80);
        vi.mocked(L.Map.prototype.getSize).mockReturnValue(L.point(100, 100));
        act(() => {
            map.fire('resize', { newSize: L.point(100, 100), oldSize: L.point(200, 200) });
        });
        expect(boundPopup.options.maxWidth).toBe(60);
        expect(boundPopup.options.maxHeight).toBe(60);
        unmount();
        expect(off).toHaveBeenCalledWith('resize', listener);
    });
});

describe('line waypoint descriptions and coordinate hover', () => {
    it.each([base.place, null])('retains hover-only coordinates with description %s', (place) => {
        const { container } = render(<LocationSection geoLocations={[{ ...geometries.line, place }]} />);
        const waypoints = [...container.querySelectorAll('.leaflet-overlay-pane path')].slice(1);
        expect(waypoints).toHaveLength(3);
        expect(container.querySelector('.leaflet-tooltip')).toBeNull();
        waypoints.forEach((waypoint, index) => {
            fireEvent.mouseOver(waypoint);
            const p = geometries.line.polygon_points![index];
            expect(container.querySelector('.leaflet-tooltip')).toHaveTextContent(`Lat: ${p.latitude.toFixed(6)}, Lon: ${p.longitude.toFixed(6)}`);
            fireEvent.mouseOut(waypoint);
            expect(container.querySelector('.leaflet-tooltip')).toBeNull();
            fireEvent.click(waypoint);
            if (place === null) {
                expect(popup(container)).toBeNull();
                expect(waypoint).not.toHaveAttribute('role');
                expect(waypoint).toHaveClass('cursor-grab!');
            } else {
                expect(popup(container)).toHaveTextContent(place!);
                fireEvent.click(screen.getByRole('button', { name: 'Close popup' }));
            }
            expect(container.querySelector('.leaflet-tooltip')).toBeNull();
        });
    });

    it('hides coordinates when opening the popup and restores hover after closing', () => {
        const { container } = render(<LocationSection geoLocations={[geometries.line]} />);
        const waypoint = screen.getByRole('button', { name: 'Show coverage description for waypoint 1' });
        fireEvent.mouseOver(waypoint);
        expect(container.querySelector('.leaflet-tooltip')).toBeInTheDocument();
        fireEvent.click(waypoint);
        expect(container.querySelector('.leaflet-tooltip')).toBeNull();
        fireEvent.mouseOut(waypoint);
        fireEvent.mouseOver(waypoint);
        expect(container.querySelector('.leaflet-tooltip')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Close popup' }));
        fireEvent.mouseOut(waypoint);
        fireEvent.mouseOver(waypoint);
        expect(container.querySelector('.leaflet-tooltip')).toBeInTheDocument();
    });

    it('opens waypoint descriptions from the keyboard and removes a visible tooltip on unmount', () => {
        const { container, unmount } = render(<LocationSection geoLocations={[geometries.line]} />);
        const waypoint = screen.getByRole('button', { name: 'Show coverage description for waypoint 2' });
        fireEvent.keyDown(waypoint, { key: ' ' });
        expect(popup(container)).toHaveTextContent(base.place!);
        fireEvent.click(screen.getByRole('button', { name: 'Close popup' }));
        fireEvent.mouseOver(waypoint);
        expect(container.querySelector('.leaflet-tooltip')).toBeInTheDocument();
        unmount();
        expect(document.querySelector('.leaflet-tooltip')).toBeNull();
    });
});
