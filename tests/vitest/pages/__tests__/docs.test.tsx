import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { __testing as basePathTesting } from '@/lib/base-path';
import Docs from '@/pages/docs';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

describe('Docs page', () => {
    afterEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('renders collapsible triggers', () => {
        render(<Docs />);
        expect(screen.getByText('For Users')).toBeInTheDocument();
        expect(screen.getByText('For Admins')).toBeInTheDocument();
        expect(screen.getByText('For Developers')).toBeInTheDocument();
    });

    it('toggles admin collapsible content', () => {
        render(<Docs />);
        const trigger = screen.getByText('For Admins');
        const content = screen.getByTestId('admin-collapsible-content');
        expect(content).toHaveAttribute('data-state', 'closed');
        fireEvent.click(trigger);
        expect(content).toHaveAttribute('data-state', 'open');
        expect(screen.getByText(/php artisan add-user/i)).toBeInTheDocument();
        expect(screen.getByText(/php artisan spdx:sync-licenses/i)).toBeInTheDocument();
    });

    it('links to user documentation', () => {
        render(<Docs />);
        fireEvent.click(screen.getByText('For Users'));
        const link = screen.getByText('Go to the user documentation');
        expect(link).toHaveAttribute('href', '/docs/users');
    });

    it('links to API documentation', () => {
        render(<Docs />);
        fireEvent.click(screen.getByText('For Developers'));
        const link = screen.getByText('View the API documentation');
        expect(link).toHaveAttribute('href', '/api/v1/doc');
    });

    it('applies the base path to documentation links when configured', () => {
        basePathTesting.setMetaBasePath('/ernie');
        render(<Docs />);
        fireEvent.click(screen.getByText('For Users'));
        expect(screen.getByText('Go to the user documentation')).toHaveAttribute('href', '/ernie/docs/users');
        fireEvent.click(screen.getByText('For Developers'));
        expect(screen.getByText('View the API documentation')).toHaveAttribute('href', '/ernie/api/v1/doc');
    });
});

