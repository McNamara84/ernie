import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { __testing as basePathTesting } from '@/lib/base-path';
import Docs from '@/pages/docs';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

// Mock IntersectionObserver for scroll spy
global.IntersectionObserver = class IntersectionObserver {
    observe() {}
    disconnect() {}
    unobserve() {}
    takeRecords() {
        return [];
    }
    root = null;
    rootMargin = '';
    thresholds = [];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
} as any;

describe('Docs page', () => {
    afterEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('renders documentation for beginner role', () => {
        render(<Docs userRole="beginner" />);
        expect(screen.getAllByText('Quick Start Guide').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Curation Workflow').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Landing Pages').length).toBeGreaterThan(0);
        expect(screen.getAllByText('DOI Registration').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('hides user management section for beginners', () => {
        render(<Docs userRole="beginner" />);
        expect(screen.queryByText('User Management')).not.toBeInTheDocument();
        expect(screen.queryByText('System Administration')).not.toBeInTheDocument();
    });

    it('shows user management for group_leader', () => {
        render(<Docs userRole="group_leader" />);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
    });

    it('hides system administration for group_leader', () => {
        render(<Docs userRole="group_leader" />);
        expect(screen.queryByText('System Administration')).not.toBeInTheDocument();
    });

    it('shows all sections for admin', () => {
        render(<Docs userRole="admin" />);
        expect(screen.getAllByText('Quick Start Guide').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Curation Workflow').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Landing Pages').length).toBeGreaterThan(0);
        expect(screen.getAllByText('DOI Registration').length).toBeGreaterThan(0);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
        expect(screen.getAllByText('System Administration').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('displays beginner restriction notice in DOI registration', () => {
        render(<Docs userRole="beginner" />);
        expect(screen.getByText(/Note for Beginners/i)).toBeInTheDocument();
        expect(screen.getAllByText(/test mode/i).length).toBeGreaterThan(0);
    });

    it('does not show beginner notice for curator role', () => {
        render(<Docs userRole="curator" />);
        expect(screen.queryByText(/Note for Beginners/i)).not.toBeInTheDocument();
    });

    it('links to API documentation', () => {
        render(<Docs userRole="curator" />);
        const link = screen.getByText('View API Documentation');
        expect(link).toHaveAttribute('href', '/api/v1/doc');
    });

    it('applies the base path to API documentation link when configured', () => {
        basePathTesting.setMetaBasePath('/ernie');
        render(<Docs userRole="curator" />);
        expect(screen.getByText('View API Documentation')).toHaveAttribute(
            'href',
            '/ernie/api/v1/doc',
        );
    });
});
