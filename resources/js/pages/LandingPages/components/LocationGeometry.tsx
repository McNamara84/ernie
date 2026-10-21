import type L from 'leaflet';
import { type RefObject, useEffect, useId, useMemo, useRef, useState } from 'react';
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

function CoverageDescriptionPopup({ description, layerRef }: { description: string; layerRef: RefObject<L.Marker | L.Path | null> }) {
    const map = useMap();
    const popupRef = useRef<L.Popup>(null);
    const descriptionRef = useRef<HTMLDivElement>(null);
    const popupId = useId();
    const descriptionId = `${popupId}-description`;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const layer = layerRef.current;
        if (!layer) return;
        let trigger: Element | undefined;
        let dialog: HTMLElement | undefined;
        let focusInside = false;
        const configureTrigger = () => {
            trigger = layer.getElement();
            trigger?.setAttribute('aria-haspopup', 'dialog');
            trigger?.setAttribute('aria-expanded', 'false');
        };
        const trackFocus = (event: FocusEvent) => {
            focusInside = dialog?.contains(event.target as Node) ?? false;
        };
        const show = (event: L.PopupEvent) => {
            if (event.popup !== popupRef.current) return;
            dialog = event.popup.getElement();
            dialog?.setAttribute('id', popupId);
            dialog?.setAttribute('role', 'dialog');
            dialog?.setAttribute('aria-label', 'Coverage description');
            dialog?.setAttribute('aria-modal', 'false');
            dialog?.setAttribute('aria-describedby', descriptionId);
            dialog?.removeAttribute('aria-hidden');
            trigger?.setAttribute('aria-controls', popupId);
            trigger?.setAttribute('aria-expanded', 'true');
            document.addEventListener('focusin', trackFocus);
            setOpen(true);
        };
        const hide = (event: L.PopupEvent) => {
            if (event.popup !== popupRef.current) return;
            // Without Leaflet's fade animation, the focused DOM node has already been removed.
            const restoreFocus = focusInside && (dialog?.contains(document.activeElement) || document.activeElement === document.body);
            trigger?.setAttribute('aria-expanded', 'false');
            trigger?.removeAttribute('aria-controls');
            document.removeEventListener('focusin', trackFocus);
            focusInside = false;
            setOpen(false);
            if (restoreFocus && trigger?.isConnected && (trigger instanceof HTMLElement || trigger instanceof SVGElement)) {
                trigger.focus({ preventScroll: true });
            }
            dialog?.setAttribute('aria-hidden', 'true');
        };
        // Popup effects can run before the source layer has been added to the map.
        layer.on('add', configureTrigger);
        configureTrigger();
        map.on('popupopen', show);
        map.on('popupclose', hide);
        return () => {
            layer.off('add', configureTrigger);
            map.off('popupopen', show);
            map.off('popupclose', hide);
            document.removeEventListener('focusin', trackFocus);
            trigger?.removeAttribute('aria-haspopup');
            trigger?.removeAttribute('aria-expanded');
            trigger?.removeAttribute('aria-controls');
        };
    }, [descriptionId, layerRef, map, popupId]);

    useEffect(() => {
        // React-Leaflet mounts the description portal after popupopen; focus its complete text once it exists.
        if (open) descriptionRef.current?.focus({ preventScroll: true });
    }, [open]);

    useEffect(() => {
        const resize = () => {
            if (popupRef.current) {
                Object.assign(popupRef.current.options, popupSize(map));
                popupRef.current.update();
            }
        };
        // Leaflet's map-level Escape handler is inactive while a marker, path or close button has focus.
        const container = map.getContainer();
        const closeFromKeyboard = (event: KeyboardEvent) => {
            // Leaflet renders its close control as an anchor with role=button; add the button's Space behavior.
            const closeWithSpace =
                event.key === ' ' &&
                event.target instanceof Element &&
                event.target.matches('.leaflet-popup-close-button') &&
                popupRef.current?.getElement()?.contains(event.target);
            if ((event.key === 'Escape' || closeWithSpace) && popupRef.current?.isOpen()) {
                event.preventDefault();
                event.stopPropagation();
                popupRef.current.close();
            }
        };
        map.on('resize', resize);
        container.addEventListener('keydown', closeFromKeyboard);
        return () => {
            map.off('resize', resize);
            container.removeEventListener('keydown', closeFromKeyboard);
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
            <div
                ref={descriptionRef}
                id={descriptionId}
                tabIndex={-1}
                className={`text-sm [overflow-wrap:anywhere] whitespace-pre-wrap text-gray-900 ${PATH_FOCUS_STYLE}`}
            >
                {description}
            </div>
        </Popup>
    );
}

/** All described geometries use the same button semantics and keyboard activation. */
function useDescriptionTrigger<T extends L.Marker | L.Path>(enabled: boolean, label: string) {
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
    const ref = useDescriptionTrigger<L.CircleMarker>(description !== null, `Show coverage description for waypoint ${index + 1}`);
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
            {description !== null && <CoverageDescriptionPopup description={description} layerRef={ref} />}
        </CircleMarker>
    );
}

/** Each displayed geometry keeps its own popup, including historical entries with multiple geometries. */
export function GeoLocationLayer({ geoLocation }: { geoLocation: GeoLocation }) {
    const description = typeof geoLocation.place === 'string' && geoLocation.place.trim() !== '' ? geoLocation.place : null;
    const interactive = description !== null;
    const markerRef = useDescriptionTrigger<L.Marker>(interactive, 'Show coverage description for point');
    const rectangleRef = useDescriptionTrigger<L.Rectangle>(interactive, 'Show coverage description for bounding box');
    const polygonRef = useDescriptionTrigger<L.Polygon>(interactive, 'Show coverage description for polygon');
    const lineRef = useDescriptionTrigger<L.Polyline>(interactive, 'Show coverage description for line');

    return (
        <>
            {hasPoint(geoLocation) && (
                <Marker
                    ref={markerRef}
                    position={[geoLocation.point_latitude!, geoLocation.point_longitude!]}
                    interactive={interactive}
                    keyboard={interactive}
                >
                    {description !== null && <CoverageDescriptionPopup description={description} layerRef={markerRef} />}
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
                    {description !== null && <CoverageDescriptionPopup description={description} layerRef={rectangleRef} />}
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
                    {description !== null && <CoverageDescriptionPopup description={description} layerRef={polygonRef} />}
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
                        {description !== null && <CoverageDescriptionPopup description={description} layerRef={lineRef} />}
                    </Polyline>
                    {geoLocation.polygon_points!.map((point, index) => (
                        <LineWaypoint key={`${geoLocation.id}-line-waypoint-${index}`} point={point} description={description} index={index} />
                    ))}
                </>
            )}
        </>
    );
}
