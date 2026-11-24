import { render, screen, within } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import UsageStatistics from '@/pages/usage-statistics';

const usePage = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', async () => {
    const { createInertiaMock } = await import('@tests/vitest/test-helpers/inertia-mocks');
    return createInertiaMock({ usePageFn: usePage });
});
vi.mock('@/hooks/use-nprogress', () => ({ useNProgress: vi.fn() }));

const privacyUrl = 'https://example.org/privacy';

describe('counting method navigation', () => {
    it.each([
        ['resource', '/10.1234/example/data', 'Back to resource'],
        ['igsn', '/10.5678/example/sample', 'Back to IGSN sample'],
    ] as const)('links directly to the originating %s', (kind, href, label) => {
        render(<UsageStatistics usageStatus={{ startedAt: '2026-10-01T00:00:00Z' }} privacyUrl={privacyUrl} returnTarget={{ href, kind }} />);

        const navigation = within(screen.getByRole('navigation', { name: 'Usage navigation' }));
        expect(navigation.getAllByRole('link')).toHaveLength(1);
        expect(navigation.getByRole('link', { name: label })).toHaveAttribute('href', href);
        expect(screen.getByText(/Collection started on 2026-10-01/)).toBeInTheDocument();
    });

    it.each([
        ['a guest', null],
        ['a signed-in reader', { id: 1 }],
    ])('offers both portals and public site navigation to %s on direct entry', (_reader, user) => {
        usePage.mockReturnValue({ props: { auth: { user } } });
        render(<UsageStatistics usageStatus={{ startedAt: null }} privacyUrl={privacyUrl} returnTarget={null} />);

        const navigation = within(screen.getByRole('navigation', { name: 'Usage navigation' }));
        expect(navigation.getAllByRole('link')).toHaveLength(2);
        expect(navigation.getByRole('link', { name: 'Data Portal' })).toHaveAttribute('href', '/doi-search');
        expect(navigation.getByRole('link', { name: 'IGSN Portal' })).toHaveAttribute('href', '/igsn-search');
        expect(screen.getByRole('link', { name: 'GFZ Data Services privacy policy' })).toHaveAttribute('href', privacyUrl);
        expect(screen.getByText(/Collection has not started/)).toBeInTheDocument();
        expect(document.querySelector('a[href="/login"], a[href="/dashboard"], a[href="/changelog"]')).toBeNull();
    });
});
