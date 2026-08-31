import { describe, expect, it } from 'vitest';

import { formatInertiaPageTitle } from '@/lib/inertia-page-title';

describe('formatInertiaPageTitle', () => {
    it('uses the complete server-owned title for resource landing pages', () => {
        expect(
            formatInertiaPageTitle(
                '',
                {
                    component: 'LandingPages/default_gfz',
                    props: { documentTitle: 'Dataset title | GFZ Data Services' },
                },
                'ERNIE',
            ),
        ).toBe('Dataset title | GFZ Data Services');
    });

    it('uses the complete server-owned title for IGSN previews without adding ERNIE', () => {
        expect(
            formatInertiaPageTitle(
                'Ignored component title',
                {
                    component: 'LandingPages/default_gfz_igsn',
                    props: { documentTitle: 'Preview: Sample title | GFZ Data Services' },
                },
                'ERNIE',
            ),
        ).toBe('Preview: Sample title | GFZ Data Services');
    });

    it('preserves the established title format for internal ERNIE pages', () => {
        expect(
            formatInertiaPageTitle('Dashboard', { component: 'dashboard', props: {} }, 'ERNIE'),
        ).toBe('Dashboard - ERNIE');
    });

    it('falls back to the application name for untitled internal pages', () => {
        expect(formatInertiaPageTitle('', { component: 'dashboard', props: {} }, 'ERNIE')).toBe('ERNIE');
    });

    it.each([undefined, null, '', '   ', 42])(
        'falls back safely when a landing-page document title is unavailable (%s)',
        (documentTitle) => {
            expect(
                formatInertiaPageTitle(
                    'Fallback title',
                    { component: 'LandingPages/default_gfz', props: { documentTitle } },
                    'ERNIE',
                ),
            ).toBe('Fallback title - ERNIE');
        },
    );
});
