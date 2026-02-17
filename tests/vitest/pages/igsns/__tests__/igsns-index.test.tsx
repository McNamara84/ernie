import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const routerMock = vi.hoisted(() => ({ get: vi.fn() }));
const axiosMock = vi.hoisted(() => ({
    default: { get: vi.fn(), delete: vi.fn(), post: vi.fn() },
    isAxiosError: vi.fn(() => false),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: routerMock,
}));

vi.mock('axios', () => axiosMock);

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@/components/igsns/status-badge', () => ({
    IgsnStatusBadge: ({ status }: { status: string }) => <span data-testid="status-badge">{status}</span>,
}));

vi.mock('@/components/igsns/bulk-actions-toolbar', () => ({
    BulkActionsToolbar: () => <div data-testid="bulk-actions" />,
}));

vi.mock('@/components/landing-pages/modals/SetupIgsnLandingPageModal', () => ({
    default: () => null,
}));

vi.mock('@/components/ui/validation-error-modal', () => ({
    ValidationErrorModal: () => null,
}));

vi.mock('@/lib/blob-utils', () => ({
    extractErrorMessageFromBlob: vi.fn(),
    parseValidationErrorFromBlob: vi.fn(),
}));

import IgsnsPage from '@/pages/igsns/index';

function createProps(overrides = {}) {
    return {
        igsns: [
            {
                id: 1,
                igsn: 'IGSN:10273/ABC001',
                title: 'Rock Sample Alpha',
                sample_type: 'Core',
                material: 'Granite',
                collection_date: '2024-01-15',
                latitude: 52.38,
                longitude: 13.06,
                upload_status: 'registered',
                upload_error_message: null,
                parent_resource_id: null,
                collector: 'John Doe',
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-02T00:00:00Z',
            },
        ],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
            from: 1,
            to: 1,
            has_more: false,
        },
        sort: {
            key: 'updated_at' as const,
            direction: 'desc' as const,
        },
        canDelete: true,
        ...overrides,
    };
}

describe('IgsnsPage', () => {
    it('renders within AppLayout', () => {
        render(<IgsnsPage {...createProps()} />);
        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
    });

    it('renders the page heading', () => {
        render(<IgsnsPage {...createProps()} />);
        expect(screen.getByText('Physical Samples (IGSNs)')).toBeInTheDocument();
    });

    it('renders IGSN data in the table', () => {
        render(<IgsnsPage {...createProps()} />);
        expect(screen.getByText('IGSN:10273/ABC001')).toBeInTheDocument();
        expect(screen.getByText('Rock Sample Alpha')).toBeInTheDocument();
    });

    it('renders status badges', () => {
        render(<IgsnsPage {...createProps()} />);
        expect(screen.getByTestId('status-badge')).toBeInTheDocument();
    });

    it('shows empty state when no IGSNs', () => {
        render(<IgsnsPage {...createProps({ igsns: [] })} />);
        expect(screen.getByText(/No IGSNs found/i)).toBeInTheDocument();
    });

    it('shows pagination info', () => {
        render(<IgsnsPage {...createProps()} />);
        expect(screen.getByText(/Showing.*1.*to.*1.*of.*1/)).toBeInTheDocument();
    });

    it('renders multiple IGSNs', () => {
        const igsns = [
            {
                id: 1,
                igsn: 'IGSN:10273/ABC001',
                title: 'Sample 1',
                sample_type: 'Core',
                material: 'Granite',
                collection_date: null,
                latitude: null,
                longitude: null,
                upload_status: 'pending',
                upload_error_message: null,
                parent_resource_id: null,
                collector: null,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                igsn: 'IGSN:10273/ABC002',
                title: 'Sample 2',
                sample_type: 'Dredge',
                material: 'Basalt',
                collection_date: '2024-03-20',
                latitude: null,
                longitude: null,
                upload_status: 'registered',
                upload_error_message: null,
                parent_resource_id: null,
                collector: null,
                created_at: '2024-02-01T00:00:00Z',
                updated_at: '2024-02-01T00:00:00Z',
            },
        ];
        render(<IgsnsPage {...createProps({ igsns })} />);
        expect(screen.getByText('Sample 1')).toBeInTheDocument();
        expect(screen.getByText('Sample 2')).toBeInTheDocument();
    });
});
