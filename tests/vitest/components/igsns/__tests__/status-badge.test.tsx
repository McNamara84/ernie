import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { IgsnStatusBadge } from '@/components/igsns/status-badge';

describe('IgsnStatusBadge', () => {
    const statuses = [
        { status: 'pending', label: 'Pending' },
        { status: 'uploaded', label: 'Uploaded' },
        { status: 'validating', label: 'Validating' },
        { status: 'validated', label: 'Validated' },
        { status: 'registering', label: 'Registering' },
        { status: 'registered', label: 'Registered' },
        { status: 'error', label: 'Error' },
    ] as const;

    describe('rendering', () => {
        it.each(statuses)('renders "$label" for status "$status"', ({ status, label }) => {
            render(<IgsnStatusBadge status={status} />);
            expect(screen.getByText(label)).toBeInTheDocument();
        });

        it('renders with correct Badge element', () => {
            const { container } = render(<IgsnStatusBadge status="pending" />);
            const badge = container.querySelector('[class*="badge"]') ?? container.firstElementChild;
            expect(badge).toBeInTheDocument();
            expect(badge).toHaveTextContent('Pending');
        });
    });

    describe('styling', () => {
        it('applies destructive variant for error status', () => {
            const { container } = render(<IgsnStatusBadge status="error" />);
            const badge = container.firstElementChild;
            expect(badge?.className).toContain('destructive');
        });

        it('applies green classes for registered status', () => {
            const { container } = render(<IgsnStatusBadge status="registered" />);
            const badge = container.firstElementChild;
            expect(badge?.className).toContain('green');
        });

        it('applies gray classes for pending status', () => {
            const { container } = render(<IgsnStatusBadge status="pending" />);
            const badge = container.firstElementChild;
            expect(badge?.className).toContain('gray');
        });

        it('passes additional className', () => {
            const { container } = render(<IgsnStatusBadge status="pending" className="my-custom-class" />);
            const badge = container.firstElementChild;
            expect(badge?.className).toContain('my-custom-class');
        });
    });

    describe('case handling', () => {
        it('normalizes uppercase status to lowercase', () => {
            render(<IgsnStatusBadge status="PENDING" />);
            expect(screen.getByText('Pending')).toBeInTheDocument();
        });

        it('normalizes mixed-case status', () => {
            render(<IgsnStatusBadge status="Registered" />);
            expect(screen.getByText('Registered')).toBeInTheDocument();
        });
    });

    describe('edge cases', () => {
        it('renders unknown status as-is with outline variant', () => {
            render(<IgsnStatusBadge status="unknown_status" />);
            expect(screen.getByText('unknown_status')).toBeInTheDocument();
        });
    });
});
