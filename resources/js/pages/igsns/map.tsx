import 'leaflet/dist/leaflet.css';

import { Head } from '@inertiajs/react';
import L from 'leaflet';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import { useEffect, useMemo } from 'react';
import { MapContainer, Marker, Popup, TileLayer, useMap } from 'react-leaflet';

import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

// Fix Leaflet default marker icons (bundler issue)
const iconPrototype: unknown = L.Icon.Default.prototype;
delete (iconPrototype as { _getIconUrl?: () => string })._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

interface GeoLocation {
    id: number;
    latitude: number;
    longitude: number;
    place: string | null;
}

interface IgsnMapItem {
    id: number;
    igsn: string | null;
    title: string;
    creator: string;
    publication_year: number | null;
    geoLocations: GeoLocation[];
}

interface IgsnMapPageProps {
    igsns: IgsnMapItem[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'IGSNs Map',
        href: '/igsns-map',
    },
];

/**
 * Calculate bounds that encompass all markers
 */
function calculateBounds(igsns: IgsnMapItem[]): L.LatLngBounds {
    const allPoints: L.LatLngTuple[] = [];

    igsns.forEach((igsn) => {
        igsn.geoLocations.forEach((geo) => {
            allPoints.push([geo.latitude, geo.longitude]);
        });
    });

    if (allPoints.length === 0) {
        // Fallback: World view
        return L.latLngBounds([
            [-60, -180],
            [80, 180],
        ]);
    }

    if (allPoints.length === 1) {
        // Single point: Create a small area around it
        const [lat, lng] = allPoints[0];
        return L.latLngBounds([
            [lat - 0.5, lng - 0.5],
            [lat + 0.5, lng + 0.5],
        ]);
    }

    return L.latLngBounds(allPoints);
}

/**
 * Component to auto-fit map bounds
 */
function FitBoundsControl({ bounds }: { bounds: L.LatLngBounds }) {
    const map = useMap();

    useEffect(() => {
        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [50, 50] });
        }
    }, [map, bounds]);

    return null;
}

export default function IgsnMapPage({ igsns }: IgsnMapPageProps) {
    const bounds = useMemo(() => calculateBounds(igsns), [igsns]);

    // Flatten all markers with their parent IGSN info
    const markers = useMemo(() => {
        return igsns.flatMap((igsn) =>
            igsn.geoLocations.map((geo) => ({
                ...geo,
                igsn: igsn.igsn,
                title: igsn.title,
                creator: igsn.creator,
                publicationYear: igsn.publication_year,
            })),
        );
    }, [igsns]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IGSNs Map" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">IGSNs Map</h1>
                    <span className="text-muted-foreground text-sm">
                        {markers.length} location{markers.length !== 1 ? 's' : ''} from {igsns.length} IGSN
                        {igsns.length !== 1 ? 's' : ''}
                    </span>
                </div>

                <div className="border-sidebar-border bg-sidebar relative flex-1 overflow-hidden rounded-xl border">
                    <MapContainer
                        center={[0, 0]}
                        zoom={2}
                        className="h-full min-h-[500px] w-full"
                        scrollWheelZoom={true}
                    >
                        <TileLayer
                            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                        />
                        <FitBoundsControl bounds={bounds} />

                        {markers.map((marker) => (
                            <Marker key={marker.id} position={[marker.latitude, marker.longitude]}>
                                <Popup>
                                    <div className="min-w-[200px]">
                                        <h3 className="mb-1 font-semibold">{marker.title}</h3>
                                        <p className="text-muted-foreground text-sm">
                                            <strong>Creator:</strong> {marker.creator}
                                        </p>
                                        {marker.publicationYear && (
                                            <p className="text-muted-foreground text-sm">
                                                <strong>Year:</strong> {marker.publicationYear}
                                            </p>
                                        )}
                                        {marker.igsn && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                <strong>IGSN:</strong> {marker.igsn}
                                            </p>
                                        )}
                                    </div>
                                </Popup>
                            </Marker>
                        ))}
                    </MapContainer>
                </div>
            </div>
        </AppLayout>
    );
}
