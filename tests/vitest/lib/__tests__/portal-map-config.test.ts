import { describe, expect, it } from 'vitest';

import {
    createCircleMarkerIcon,
    createIgsnMarkerIcon,
    createPieChartSvg,
    DEFAULT_MARKER_COLOR,
    escapeHtml,
    escapeHtmlAttr,
    formatAuthorsShort,
    getClusterSize,
    getResourceTypeColor,
    getShapePathOptions,
    isIgsnType,
    renderPopupHtml,
    RESOURCE_TYPE_COLORS,
} from '@/lib/portal-map-config';
import type { PortalResource } from '@/types/portal';

// ---------------------------------------------------------------------------
// RESOURCE_TYPE_COLORS constant
// ---------------------------------------------------------------------------
describe('RESOURCE_TYPE_COLORS', () => {
    it('contains exactly 34 resource type entries', () => {
        expect(Object.keys(RESOURCE_TYPE_COLORS)).toHaveLength(34);
    });

    it('includes all expected resource types', () => {
        const expectedSlugs = [
            'audiovisual', 'award', 'book', 'book-chapter', 'collection',
            'computational-notebook', 'conference-paper', 'conference-proceeding',
            'data-paper', 'dataset', 'dissertation', 'event', 'image',
            'instrument', 'interactive-resource', 'journal', 'journal-article',
            'model', 'other', 'output-management-plan', 'peer-review',
            'physical-object', 'poster', 'preprint', 'presentation', 'project',
            'report', 'service', 'software', 'sound', 'standard',
            'study-registration', 'text', 'workflow',
        ];
        expectedSlugs.forEach(slug => {
            expect(RESOURCE_TYPE_COLORS).toHaveProperty(slug);
        });
    });

    it('assigns orange to physical-object (IGSN)', () => {
        expect(RESOURCE_TYPE_COLORS['physical-object']).toBe('#F97316');
    });

    it('assigns GFZ blue to dataset', () => {
        expect(RESOURCE_TYPE_COLORS['dataset']).toBe('#0C2A63');
    });

    it('has valid hex color format for all entries', () => {
        Object.values(RESOURCE_TYPE_COLORS).forEach(color => {
            expect(color).toMatch(/^#[0-9A-Fa-f]{6}$/);
        });
    });

    it('has all unique colors (no duplicates)', () => {
        const colors = Object.values(RESOURCE_TYPE_COLORS);
        const uniqueColors = new Set(colors);
        expect(uniqueColors.size).toBe(colors.length);
    });
});

// ---------------------------------------------------------------------------
// getResourceTypeColor
// ---------------------------------------------------------------------------
describe('getResourceTypeColor', () => {
    it('returns the correct color for a known slug', () => {
        expect(getResourceTypeColor('dataset')).toBe('#0C2A63');
        expect(getResourceTypeColor('software')).toBe('#22C55E');
        expect(getResourceTypeColor('physical-object')).toBe('#F97316');
    });

    it('returns DEFAULT_MARKER_COLOR for null slug', () => {
        expect(getResourceTypeColor(null)).toBe(DEFAULT_MARKER_COLOR);
    });

    it('returns DEFAULT_MARKER_COLOR for unknown slug', () => {
        expect(getResourceTypeColor('unknown-type')).toBe(DEFAULT_MARKER_COLOR);
    });

    it('returns DEFAULT_MARKER_COLOR for empty string', () => {
        expect(getResourceTypeColor('')).toBe(DEFAULT_MARKER_COLOR);
    });
});

// ---------------------------------------------------------------------------
// isIgsnType
// ---------------------------------------------------------------------------
describe('isIgsnType', () => {
    it('returns true for physical-object', () => {
        expect(isIgsnType('physical-object')).toBe(true);
    });

    it('returns false for other slugs', () => {
        expect(isIgsnType('dataset')).toBe(false);
        expect(isIgsnType('software')).toBe(false);
        expect(isIgsnType('other')).toBe(false);
    });

    it('returns false for null', () => {
        expect(isIgsnType(null)).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// escapeHtml
// ---------------------------------------------------------------------------
describe('escapeHtml', () => {
    it('escapes angle brackets', () => {
        expect(escapeHtml('<script>alert("xss")</script>')).toBe(
            '&lt;script&gt;alert("xss")&lt;/script&gt;',
        );
    });

    it('escapes ampersand', () => {
        expect(escapeHtml('A & B')).toBe('A &amp; B');
    });

    it('returns plain text unchanged', () => {
        expect(escapeHtml('Hello World')).toBe('Hello World');
    });

    it('handles empty string', () => {
        expect(escapeHtml('')).toBe('');
    });
});

// ---------------------------------------------------------------------------
// escapeHtmlAttr
// ---------------------------------------------------------------------------
describe('escapeHtmlAttr', () => {
    it('escapes double quotes', () => {
        expect(escapeHtmlAttr('"hello"')).toBe('&quot;hello&quot;');
    });

    it('escapes single quotes', () => {
        expect(escapeHtmlAttr("it's")).toBe('it&#39;s');
    });

    it('escapes angle brackets', () => {
        expect(escapeHtmlAttr('<script>')).toBe('&lt;script&gt;');
    });

    it('escapes ampersand', () => {
        expect(escapeHtmlAttr('a&b')).toBe('a&amp;b');
    });

    it('returns plain text unchanged', () => {
        expect(escapeHtmlAttr('hello')).toBe('hello');
    });
});

// ---------------------------------------------------------------------------
// formatAuthorsShort
// ---------------------------------------------------------------------------
describe('formatAuthorsShort', () => {
    it('returns "Unknown" for empty array', () => {
        expect(formatAuthorsShort([])).toBe('Unknown');
    });

    it('returns single author name', () => {
        expect(formatAuthorsShort([{ name: 'Doe, John' }])).toBe('Doe, John');
    });

    it('joins two authors with ampersand', () => {
        expect(formatAuthorsShort([{ name: 'Doe, John' }, { name: 'Smith, Jane' }])).toBe(
            'Doe, John & Smith, Jane',
        );
    });

    it('abbreviates three or more authors with et al.', () => {
        expect(
            formatAuthorsShort([
                { name: 'Doe, John' },
                { name: 'Smith, Jane' },
                { name: 'Brown, Bob' },
            ]),
        ).toBe('Doe, John et al.');
    });
});

// ---------------------------------------------------------------------------
// createIgsnMarkerIcon
// ---------------------------------------------------------------------------
describe('createIgsnMarkerIcon', () => {
    it('returns a DivIcon', () => {
        const icon = createIgsnMarkerIcon();
        expect(icon).toBeDefined();
        expect(icon.options.className).toBe('portal-igsn-marker');
    });

    it('has correct icon size', () => {
        const icon = createIgsnMarkerIcon();
        expect(icon.options.iconSize).toEqual([20, 20]);
    });

    it('has centered anchor', () => {
        const icon = createIgsnMarkerIcon();
        expect(icon.options.iconAnchor).toEqual([10, 10]);
    });

    it('generates SVG with orange fill', () => {
        const icon = createIgsnMarkerIcon();
        expect(icon.options.html).toContain('#F97316');
    });

    it('generates SVG with diamond shape (rotated rect)', () => {
        const icon = createIgsnMarkerIcon();
        expect(icon.options.html).toContain('rotate(45');
        expect(icon.options.html).toContain('<rect');
    });
});

// ---------------------------------------------------------------------------
// createCircleMarkerIcon
// ---------------------------------------------------------------------------
describe('createCircleMarkerIcon', () => {
    it('returns a DivIcon', () => {
        const icon = createCircleMarkerIcon('dataset');
        expect(icon).toBeDefined();
        expect(icon.options.className).toBe('portal-circle-marker');
    });

    it('has correct icon size', () => {
        const icon = createCircleMarkerIcon('dataset');
        expect(icon.options.iconSize).toEqual([18, 18]);
    });

    it('generates SVG with the correct color for the slug', () => {
        const icon = createCircleMarkerIcon('software');
        expect(icon.options.html).toContain('#22C55E');
    });

    it('uses DEFAULT_MARKER_COLOR for null slug', () => {
        const icon = createCircleMarkerIcon(null);
        expect(icon.options.html).toContain(DEFAULT_MARKER_COLOR);
    });

    it('generates SVG circle element', () => {
        const icon = createCircleMarkerIcon('dataset');
        expect(icon.options.html).toContain('<circle');
    });
});

// ---------------------------------------------------------------------------
// getShapePathOptions
// ---------------------------------------------------------------------------
describe('getShapePathOptions', () => {
    it('returns correct options for box type', () => {
        const opts = getShapePathOptions('dataset', 'box');
        expect(opts.color).toBe('#0C2A63');
        expect(opts.weight).toBe(2);
        expect(opts.fillOpacity).toBe(0.2);
        expect(opts.fillColor).toBe('#0C2A63');
    });

    it('returns correct options for polygon type', () => {
        const opts = getShapePathOptions('software', 'polygon');
        expect(opts.color).toBe('#22C55E');
        expect(opts.weight).toBe(2);
        expect(opts.fillOpacity).toBe(0.2);
    });

    it('returns dashed line options for line type', () => {
        const opts = getShapePathOptions('dataset', 'line');
        expect(opts.color).toBe('#0C2A63');
        expect(opts.weight).toBe(3);
        expect(opts.dashArray).toBe('8, 4');
        expect(opts.opacity).toBe(0.8);
        expect(opts).not.toHaveProperty('fillOpacity');
    });

    it('uses DEFAULT_MARKER_COLOR for null slug', () => {
        const opts = getShapePathOptions(null, 'box');
        expect(opts.color).toBe(DEFAULT_MARKER_COLOR);
    });
});

// ---------------------------------------------------------------------------
// renderPopupHtml
// ---------------------------------------------------------------------------
describe('renderPopupHtml', () => {
    const baseResource: PortalResource = {
        id: 1,
        doi: '10.5880/test.2026.001',
        title: 'Test Dataset',
        creators: [{ name: 'Doe, John' }],
        year: 2026,
        resourceType: 'Dataset',
        resourceTypeSlug: 'dataset',
        isIgsn: false,
        geoLocations: [],
        landingPageUrl: '/landing/1',
    };

    it('renders resource title', () => {
        const html = renderPopupHtml(baseResource);
        expect(html).toContain('Test Dataset');
    });

    it('renders resource type badge', () => {
        const html = renderPopupHtml(baseResource);
        expect(html).toContain('Dataset');
    });

    it('renders author name', () => {
        const html = renderPopupHtml(baseResource);
        expect(html).toContain('Doe, John');
    });

    it('renders year', () => {
        const html = renderPopupHtml(baseResource);
        expect(html).toContain('2026');
    });

    it('renders landing page link', () => {
        const html = renderPopupHtml(baseResource);
        expect(html).toContain('href="/landing/1"');
        expect(html).toContain('View Details');
    });

    it('omits link when landingPageUrl is null', () => {
        const resource = { ...baseResource, landingPageUrl: null };
        const html = renderPopupHtml(resource);
        expect(html).not.toContain('href=');
    });

    it('omits year when null', () => {
        const resource = { ...baseResource, year: null };
        const html = renderPopupHtml(resource);
        expect(html).not.toContain('•');
    });

    it('uses IGSN badge styling for physical objects', () => {
        const igsnResource: PortalResource = {
            ...baseResource,
            resourceType: 'IGSN Samples',
            resourceTypeSlug: 'physical-object',
            isIgsn: true,
        };
        const html = renderPopupHtml(igsnResource);
        expect(html).toContain('#f1f5f9'); // IGSN badge bg
        expect(html).toContain('#475569'); // IGSN badge text
    });

    it('uses resource type color for non-IGSN badge', () => {
        const html = renderPopupHtml(baseResource);
        expect(html).toContain('#0C2A63'); // dataset color
    });

    it('escapes HTML in title to prevent XSS', () => {
        const resource = { ...baseResource, title: '<script>alert("xss")</script>' };
        const html = renderPopupHtml(resource);
        expect(html).toContain('&lt;script&gt;');
        expect(html).not.toContain('<script>alert');
    });

    it('escapes HTML in resource type', () => {
        const resource = { ...baseResource, resourceType: '<b>Type</b>' };
        const html = renderPopupHtml(resource);
        expect(html).toContain('&lt;b&gt;Type&lt;/b&gt;');
    });

    it('escapes HTML in landing page URL to prevent XSS', () => {
        const resource = { ...baseResource, landingPageUrl: '"/onclick="alert(1)' };
        const html = renderPopupHtml(resource);
        // The double quote should be escaped to &quot; in the href attribute
        expect(html).toContain('&quot;');
        expect(html).not.toContain('onclick="alert');
    });
});

// ---------------------------------------------------------------------------
// createPieChartSvg
// ---------------------------------------------------------------------------
describe('createPieChartSvg', () => {
    it('generates SVG with correct dimensions', () => {
        const svg = createPieChartSvg({ dataset: 5 }, 5, 40);
        expect(svg).toContain('width="40"');
        expect(svg).toContain('height="40"');
    });

    it('shows the total count in the center', () => {
        const svg = createPieChartSvg({ dataset: 3, software: 2 }, 5, 40);
        expect(svg).toContain('>5</text>');
    });

    it('renders a full circle for a single type', () => {
        const svg = createPieChartSvg({ dataset: 10 }, 10, 40);
        // Single type should produce a <circle> (not a <path>)
        const circleCount = (svg.match(/<circle/g) || []).length;
        // 3 circles: outer white bg, single-type fill, inner white center
        expect(circleCount).toBe(3);
    });

    it('renders path segments for multiple types', () => {
        const svg = createPieChartSvg({ dataset: 5, software: 5 }, 10, 40);
        expect(svg).toContain('<path');
    });

    it('uses correct colors for each type', () => {
        const svg = createPieChartSvg({ dataset: 3, software: 2 }, 5, 40);
        expect(svg).toContain(RESOURCE_TYPE_COLORS['dataset']);
        expect(svg).toContain(RESOURCE_TYPE_COLORS['software']);
    });

    it('handles three or more types', () => {
        const svg = createPieChartSvg({ dataset: 3, software: 2, image: 1 }, 6, 50);
        expect(svg).toContain(RESOURCE_TYPE_COLORS['dataset']);
        expect(svg).toContain(RESOURCE_TYPE_COLORS['software']);
        expect(svg).toContain(RESOURCE_TYPE_COLORS['image']);
        expect(svg).toContain('>6</text>');
    });

    it('uses DEFAULT_MARKER_COLOR for unknown slug', () => {
        const svg = createPieChartSvg({ 'unknown-slug': 3 }, 3, 40);
        expect(svg).toContain(DEFAULT_MARKER_COLOR);
    });
});

// ---------------------------------------------------------------------------
// getClusterSize
// ---------------------------------------------------------------------------
describe('getClusterSize', () => {
    it('returns 40 for a single marker (log2(1) = 0)', () => {
        expect(getClusterSize(1)).toBe(40);
    });

    it('scales logarithmically', () => {
        expect(getClusterSize(2)).toBeCloseTo(40 + 8, 5);     // log2(2) = 1
        expect(getClusterSize(4)).toBeCloseTo(40 + 16, 5);    // log2(4) = 2
        expect(getClusterSize(8)).toBeCloseTo(40 + 24, 5);    // log2(8) = 3
    });

    it('caps at maximum 70', () => {
        expect(getClusterSize(100000)).toBe(70);
    });

    it('returns values monotonically increasing up to cap', () => {
        const sizes = [1, 2, 4, 8, 16, 32].map(getClusterSize);
        for (let i = 1; i < sizes.length; i++) {
            expect(sizes[i]).toBeGreaterThanOrEqual(sizes[i - 1]);
        }
    });
});
