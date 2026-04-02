import L from 'leaflet';

import type { PortalCreator, PortalResource } from '@/types/portal';

// Leaflet type extension for storing resource type slug on markers
declare module 'leaflet' {
    interface MarkerOptions {
        resourceTypeSlug?: string;
    }
}

/**
 * Color assignments for all 34 DataCite resource types.
 * Hand-tuned for visibility on OSM tiles and mutual distinguishability.
 */
export const RESOURCE_TYPE_COLORS: Record<string, string> = {
    'audiovisual': '#8B5CF6',
    'award': '#06B6D4',
    'book': '#A16207',
    'book-chapter': '#CA8A04',
    'collection': '#0891B2',
    'computational-notebook': '#7C3AED',
    'conference-paper': '#2563EB',
    'conference-proceeding': '#3B82F6',
    'data-paper': '#0EA5E9',
    'dataset': '#0C2A63',
    'dissertation': '#4F46E5',
    'event': '#DB2777',
    'image': '#059669',
    'instrument': '#475569',
    'interactive-resource': '#6366F1',
    'journal': '#9333EA',
    'journal-article': '#A855F7',
    'model': '#14B8A6',
    'other': '#78716C',
    'output-management-plan': '#64748B',
    'peer-review': '#EC4899',
    'physical-object': '#F97316',
    'poster': '#F59E0B',
    'preprint': '#6D28D9',
    'presentation': '#D946EF',
    'project': '#10B981',
    'report': '#0D9488',
    'service': '#EF4444',
    'software': '#22C55E',
    'sound': '#E11D48',
    'standard': '#334155',
    'study-registration': '#84CC16',
    'text': '#F43F5E',
    'workflow': '#FB923C',
};

/** Fallback color for unknown resource type slugs. */
export const DEFAULT_MARKER_COLOR = '#6B7280';

/**
 * Get the color for a given resource type slug.
 */
export function getResourceTypeColor(slug: string | null): string {
    if (!slug) return DEFAULT_MARKER_COLOR;
    return RESOURCE_TYPE_COLORS[slug] ?? DEFAULT_MARKER_COLOR;
}

/**
 * Check if a resource type slug represents an IGSN sample (Physical Object).
 */
export function isIgsnType(slug: string | null): boolean {
    return slug === 'physical-object';
}

/**
 * Escape HTML to prevent XSS in Leaflet popups rendered as raw HTML strings.
 */
export function escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format authors for popup display (short form).
 */
export function formatAuthorsShort(creators: PortalCreator[]): string {
    if (creators.length === 0) return 'Unknown';
    if (creators.length === 1) return creators[0].name;
    if (creators.length === 2) return `${creators[0].name} & ${creators[1].name}`;
    return `${creators[0].name} et al.`;
}

/**
 * Create a diamond-shaped DivIcon for IGSN markers.
 */
export function createIgsnMarkerIcon(): L.DivIcon {
    const color = RESOURCE_TYPE_COLORS['physical-object'];
    const size = 20;
    const svg = `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="14" height="14" rx="2" transform="rotate(45 ${size / 2} ${size / 2})" fill="${color}" stroke="white" stroke-width="2"/></svg>`;

    return L.divIcon({
        html: svg,
        className: 'portal-igsn-marker',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -size / 2],
    });
}

/**
 * Create a colored circle DivIcon for non-IGSN point markers.
 * Uses DivIcon (not CircleMarker) so it's compatible with MarkerClusterGroup.
 */
export function createCircleMarkerIcon(slug: string | null): L.DivIcon {
    const color = getResourceTypeColor(slug);
    const size = 18;
    const svg = `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg"><circle cx="${size / 2}" cy="${size / 2}" r="${size / 2 - 1}" fill="${color}" stroke="white" stroke-width="2"/></svg>`;

    return L.divIcon({
        html: svg,
        className: 'portal-circle-marker',
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
        popupAnchor: [0, -size / 2],
    });
}

/**
 * Create PathOptions for rectangles, polygons, and polylines based on resource type.
 */
export function getShapePathOptions(slug: string | null, type: 'box' | 'polygon' | 'line'): L.PathOptions {
    const color = getResourceTypeColor(slug);

    if (type === 'line') {
        return {
            color,
            weight: 3,
            dashArray: '8, 4',
            opacity: 0.8,
        };
    }

    return {
        color,
        weight: 2,
        fillOpacity: 0.2,
        fillColor: color,
    };
}

/**
 * Escape a string for safe use inside an HTML attribute value (double-quoted).
 */
export function escapeHtmlAttr(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Render popup HTML string for a resource (used by imperative Leaflet markers in ClusterLayer).
 */
export function renderPopupHtml(resource: PortalResource): string {
    const bgColor = resource.isIgsn ? '#f1f5f9' : getResourceTypeColor(resource.resourceTypeSlug);
    const textColor = resource.isIgsn ? '#475569' : '#ffffff';
    const badgeStyle = `display:inline-block;background-color:${bgColor};color:${textColor};padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:500;`;

    const authors = escapeHtml(formatAuthorsShort(resource.creators));
    const year = resource.year ? ` \u2022 ${resource.year}` : '';
    const link = resource.landingPageUrl
        ? `<a href="${escapeHtmlAttr(resource.landingPageUrl)}" target="_blank" rel="noopener noreferrer" style="font-size:12px;font-weight:500;color:#2563eb;text-decoration:none;">View Details \u2192</a>`
        : '';

    return `<div style="min-width:200px;max-width:280px;"><span style="${badgeStyle}">${escapeHtml(resource.resourceType)}</span><h4 style="margin:8px 0 4px;font-size:13px;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${escapeHtml(resource.title)}</h4><p style="margin:0 0 8px;font-size:11px;color:#6b7280;">${authors}${year}</p>${link}</div>`;
}

/**
 * Calculate cluster icon size based on the number of child markers.
 * Scales logarithmically from 40px (single marker) to 70px (very large clusters).
 */
export function getClusterSize(count: number): number {
    return Math.min(40 + Math.log2(count) * 8, 70);
}

/**
 * Generate an SVG pie chart for a marker cluster icon.
 * Each slice represents the proportion of a given resource type in the cluster.
 */
export function createPieChartSvg(typeCounts: Record<string, number>, total: number, size: number): string {
    const r = size / 2;
    let cumulativePercent = 0;

    const slices = Object.entries(typeCounts).map(([slug, count]) => {
        const percent = count / total;
        const startAngle = cumulativePercent * 2 * Math.PI;
        cumulativePercent += percent;
        const endAngle = cumulativePercent * 2 * Math.PI;
        return { slug, startAngle, endAngle, color: getResourceTypeColor(slug) };
    });

    const paths = slices
        .map(({ startAngle, endAngle, color }) => {
            if (endAngle - startAngle >= 2 * Math.PI - 0.01) {
                return `<circle cx="${r}" cy="${r}" r="${r - 2}" fill="${color}"/>`;
            }
            const x1 = r + (r - 2) * Math.sin(startAngle);
            const y1 = r - (r - 2) * Math.cos(startAngle);
            const x2 = r + (r - 2) * Math.sin(endAngle);
            const y2 = r - (r - 2) * Math.cos(endAngle);
            const largeArc = endAngle - startAngle > Math.PI ? 1 : 0;
            return `<path d="M${r},${r} L${x1},${y1} A${r - 2},${r - 2} 0 ${largeArc},1 ${x2},${y2} Z" fill="${color}"/>`;
        })
        .join('');

    return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg"><circle cx="${r}" cy="${r}" r="${r}" fill="white"/>${paths}<circle cx="${r}" cy="${r}" r="${r * 0.45}" fill="white"/><text x="${r}" y="${r}" text-anchor="middle" dominant-baseline="central" font-size="${size * 0.3}px" font-weight="bold" fill="#374151">${total}</text></svg>`;
}
