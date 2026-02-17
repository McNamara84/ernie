import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { IgsnStatusBadge } from '@/components/igsns/status-badge';

describe('IgsnStatusBadge', () => {
    it.each([
        ['pending', 'Pending'],
        ['uploaded', 'Uploaded'],
        ['validating', 'Validating'],
        ['validated', 'Validated'],
        ['registering', 'Registering'],
        ['registered', 'Registered'],
        ['error', 'Error'],
    ])('renders correct label for status "%s"', (status, expectedLabel) => {
        render(<IgsnStatusBadge status={status} />);
        expect(screen.getByText(expectedLabel)).toBeInTheDocument();
    });

    it('renders with case-insensitive status', () => {
        render(<IgsnStatusBadge status="PENDING" />);
        expect(screen.getByText('Pending')).toBeInTheDocument();
    });

    it('uses fallback for unknown status', () => {
        render(<IgsnStatusBadge status="unknown-status" />);
        expect(screen.getByText('unknown-status')).toBeInTheDocument();
    });

    it('applies custom className', () => {
        const { container } = render(<IgsnStatusBadge status="registered" className="my-class" />);
        const badge = container.querySelector('.my-class');
        expect(badge).toBeInTheDocument();
    });

    it('renders registered status with green styling', () => {
        const { container } = render(<IgsnStatusBadge status="registered" />);
        const badge = container.querySelector('[class*="bg-green"]');
        expect(badge).toBeInTheDocument();
    });

    it('renders error status with destructive variant', () => {
        render(<IgsnStatusBadge status="error" />);
        expect(screen.getByText('Error')).toBeInTheDocument();
    });
});
