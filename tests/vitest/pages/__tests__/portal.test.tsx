import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PortalPageProps } from '@/types/portal';

const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: routerMock,
}));

vi.mock('@/layouts/portal-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="portal-layout">{children}</div>,
}));

vi.mock('@/hooks/use-portal-filters', () => ({
    usePortalFilters: () => ({
        filters: { query: null, type: 'all' },
        setSearch: vi.fn(),
        setType: vi.fn(),
        clearFilters: vi.fn(),
        hasActiveFilters: false,
    }),
}));

vi.mock('@/components/portal/PortalFilters', () => ({
    PortalFilters: () => <div data-testid="portal-filters" />,
}));

vi.mock('@/components/portal/PortalMap', () => ({
    PortalMap: () => <div data-testid="portal-map" />,
}));

vi.mock('@/components/portal/PortalResultList', () => ({
    PortalResultList: () => <div data-testid="portal-result-list" />,
}));

vi.mock('@/components/ui/resizable', () => ({
    ResizableHandle: () => <div data-testid="resizable-handle" />,
    ResizablePanel: ({ children }: { children?: React.ReactNode }) => <div data-testid="resizable-panel">{children}</div>,
    ResizablePanelGroup: ({ children }: { children?: React.ReactNode }) => <div data-testid="resizable-group">{children}</div>,
}));

import Portal from '@/pages/portal';

function createProps(overrides: Partial<PortalPageProps> = {}): PortalPageProps {
    return {
        resources: [],
        mapData: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 0,
            from: 0,
            to: 0,
        },
        filters: {
            query: null,
            type: 'all',
        },
        ...overrides,
    };
}

describe('Portal page', () => {
    it('renders within PortalLayout', () => {
        render(<Portal {...createProps()} />);
        expect(screen.getByTestId('portal-layout')).toBeInTheDocument();
    });

    it('renders the page heading', () => {
        render(<Portal {...createProps()} />);
        expect(screen.getByText('Data Portal')).toBeInTheDocument();
    });

    it('renders portal filters component', () => {
        render(<Portal {...createProps()} />);
        expect(screen.getByTestId('portal-filters')).toBeInTheDocument();
    });

    it('renders portal result list', () => {
        render(<Portal {...createProps()} />);
        // Portal renders both mobile and desktop layouts, so multiple result lists exist
        expect(screen.getAllByTestId('portal-result-list').length).toBeGreaterThanOrEqual(1);
    });

    it('renders map panel', () => {
        render(<Portal {...createProps()} />);
        expect(screen.getAllByTestId('portal-map').length).toBeGreaterThanOrEqual(1);
    });

    it('shows description text', () => {
        render(<Portal {...createProps()} />);
        expect(screen.getByText(/Discover and explore published research datasets/)).toBeInTheDocument();
    });
});
