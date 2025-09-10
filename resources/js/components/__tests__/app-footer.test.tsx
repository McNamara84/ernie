import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import AppFooter from '../app-footer';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/routes', () => ({
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

describe('AppFooter', () => {
    it('displays version and navigation links', () => {
        render(<AppFooter />);
        expect(screen.getByText('ERNIE v0.1.0')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /about/i })).toHaveAttribute('href', '/about');
        expect(screen.getByRole('link', { name: /legal notice/i })).toHaveAttribute('href', '/legal-notice');
    });
});

