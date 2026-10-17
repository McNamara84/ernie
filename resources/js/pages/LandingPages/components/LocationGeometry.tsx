import type L from 'leaflet';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CircleMarker, Marker, Polygon, Polyline, Popup, Rectangle, Tooltip, useMap } from 'react-leaflet';

import { type GeoLocation, hasBox, hasLine, hasPoint, hasPolygon } from '@/lib/landing-page-geolocation';

const GFZ_BLUE = '#0C2A63';
const PATH_FOCUS_STYLE = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500';

function popupSize(map: L.Map) {
    const { x, y } = map.getSize();
    return {
        // Leave space for the popup frame and the map controls at the upper corners.
        maxWidth: Math.max(60, Math.min(280, x - 120)),
        maxHeight: Math.max(60, Math.min(240, y - 120)),
    };
}

function CoverageDescriptionPopup({ description }: { description: string }) {
    const map = useMap();
    const popupRef = useRef<L.Popup>(null);

    useEffect(() => {
        const resize = () => {
            if (popupRef.current) {
                Object.assign(popupRef.current.options, popupSize(map));
                popupRef.current.update();
            }
        };
        // Leaflet's map-level Escape handler is inactive while a marker, path or close button has focus.
        const container = map.getContainer();
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && popupRef.current?.isOpen()) {
                event.preventDefault();
                event.stopPropagation();
                popupRef.current.close();
            }
        };
        map.on('resize', resize);
        container.addEventListener('keydown', closeOnEscape);
        return () => {
            map.off('resize', resize);
            container.removeEventListener('keydown', closeOnEscape);
        };
    }, [map]);

    return (
        <Popup
            ref={popupRef}
            {...popupSize(map)}
            minWidth={60}
            autoPanPaddingTopLeft={[8, 56]}
            autoPanPaddingBottomRight={[56, 8]}
            closeButton
            closeOnEscapeKey
            closeOnClick
            autoClose
        >
            <div className="text-sm [overflow-wrap:anywhere] whitespace-pre-wrap text-gray-900">{description}</div>
        </Popup>
    );
}

/** Leaflet paths need an explicit keyboard target; only described paths act as buttons. */
function useDescriptionPath<T extends L.Path>(enabled: boolean, label: string) {
    const ref = useRef<T>(null);

    useEffect(() => {
        const layer = ref.current;
        const element = layer?.getElement();
        if (!enabled || !layer || !element) return;

        element.setAttribute('tabindex', '0');
        element.setAttribute('role', 'button');
        element.setAttribute('aria-label', label);
        const activate = (event: Event) => {
            const key = (event as KeyboardEvent).key;
            if (key === 'Enter' || key === ' ') {
                event.preventDefault();
                event.stopPropagation();
                layer.openPopup();
            }
        };
        element.addEventListener('keydown', activate);
        return () => {
            element.removeEventListener('keydown', activate);
            element.removeAttribute('tabindex');
            element.removeAttribute('role');
            element.removeAttribute('aria-label');
        };
    }, [enabled, label]);

    return ref;
}

function LineWaypoint({ point, description, index }: { point: { longitude: number; latitude: number }; description: string | null; index: number }) {
    const ref = useDescriptionPath<L.CircleMarker>(description !== null, `Show coverage description for waypoint ${index + 1}`);
    const [hovered, setHovered] = useState(false);
    const events = useMemo<L.LeafletEventHandlerFnMap>(
        () => ({
            mouseover: () => setHovered(!ref.current?.isPopupOpen()),
            mouseout: () => setHovered(false),
            popupopen: () => setHovered(false),
        }),
        [ref],
    );

    return (
        <CircleMarker
            ref={ref}
            center={[point.latitude, point.longitude]}
            radius={5}
            className={description === null ? 'cursor-grab!' : PATH_FOCUS_STYLE}
            eventHandlers={events}
            pathOptions={{
                color: GFZ_BLUE,
                fillColor: GFZ_BLUE,
                fillOpacity: 0.85,
                weight: 1,
            }}
        >
            {/* A hover-controlled permanent tooltip has no automatic Leaflet click handler. */}
            {hovered && (
                <Tooltip direction="top" permanent sticky>
                    {'Lat: ' + point.latitude.toFixed(6) + ', Lon: ' + point.longitude.toFixed(6)}
                </Tooltip>
            )}
            {description !== null && <CoverageDescriptionPopup description={description} />}
        </CircleMarker>
    );
}

/** Each displayed geometry keeps its own popup, including historical entries with multiple geometries. */
export function GeoLocationLayer({ geoLocation }: { geoLocation: GeoLocation }) {
    const description = typeof geoLocation.place === 'string' && geoLocation.place.trim() !== '' ? geoLocation.place : null;
    const interactive = description !== null;
    const rectangleRef = useDescriptionPath<L.Rectangle>(interactive, 'Show coverage description for bounding box');
    const polygonRef = useDescriptionPath<L.Polygon>(interactive, 'Show coverage description for polygon');
    const lineRef = useDescriptionPath<L.Polyline>(interactive, 'Show coverage description for line');

    return (
        <>
            {hasPoint(geoLocation) && (
                <Marker position={[geoLocation.point_latitude!, geoLocation.point_longitude!]} interactive={interactive} keyboard={interactive}>
                    {description !== null && <CoverageDescriptionPopup description={description} />}
                </Marker>
            )}
            {hasBox(geoLocation) && (
                <Rectangle
                    ref={rectangleRef}
                    className={PATH_FOCUS_STYLE}
                    interactive={interactive}
                    bounds={[
                        [geoLocation.south_bound_latitude!, geoLocation.west_bound_longitude!],
                        [geoLocation.north_bound_latitude!, geoLocation.east_bound_longitude!],
                    ]}
                    pathOptions={{ color: GFZ_BLUE, weight: 2, fillOpacity: 0.2 }}
                >
                    {description !== null && <CoverageDescriptionPopup description={description} />}
                </Rectangle>
            )}
            {hasPolygon(geoLocation) && (
                <Polygon
                    ref={polygonRef}
                    className={PATH_FOCUS_STYLE}
                    interactive={interactive}
                    positions={geoLocation.polygon_points!.map((p): L.LatLngTuple => [p.latitude, p.longitude])}
                    pathOptions={{ color: GFZ_BLUE, weight: 2, fillOpacity: 0.2 }}
                >
                    {description !== null && <CoverageDescriptionPopup description={description} />}
                </Polygon>
            )}
            {hasLine(geoLocation) && (
                <>
                    <Polyline
                        ref={lineRef}
                        className={PATH_FOCUS_STYLE}
                        interactive={interactive}
                        positions={geoLocation.polygon_points!.map((p): L.LatLngTuple => [p.latitude, p.longitude])}
                        pathOptions={{ color: GFZ_BLUE, weight: 3, dashArray: '8, 4' }}
                    >
                        {description !== null && <CoverageDescriptionPopup description={description} />}
                    </Polyline>
                    {geoLocation.polygon_points!.map((point, index) => (
                        <LineWaypoint key={`${geoLocation.id}-line-waypoint-${index}`} point={point} description={description} index={index} />
                    ))}
                </>
            )}
        </>
    );
}
