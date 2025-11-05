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

describe('Docs page', () => {
    afterEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('renders documentation heading', () => {
        render(<Docs userRole="curator" />);
        expect(screen.getByText('Documentation')).toBeInTheDocument();
    });

    it('displays user role', () => {
        render(<Docs userRole="admin" />);
        expect(screen.getByText('admin')).toBeInTheDocument();
    });

    it('links to API documentation', () => {
        render(<Docs userRole="curator" />);
        const link = screen.getByText('API Documentation');
        expect(link).toHaveAttribute('href', '/api/v1/doc');
    });

    it('applies the base path to API documentation link when configured', () => {
        basePathTesting.setMetaBasePath('/ernie');
        render(<Docs userRole="curator" />);
        expect(screen.getByText('API Documentation')).toHaveAttribute('href', '/ernie/api/v1/doc');
    });
});
