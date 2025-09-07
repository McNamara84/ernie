import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { Breadcrumbs } from '../breadcrumbs';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
}));

describe('Breadcrumbs', () => {
    it('renders links and current page', () => {
        const items = [
            { title: 'Home', href: '/' },
            { title: 'Dashboard', href: '/dashboard' },
        ];
        render(<Breadcrumbs breadcrumbs={items} />);
        const home = screen.getByRole('link', { name: 'Home' });
        expect(home).toHaveAttribute('href', '/');
        const current = screen.getByText('Dashboard');
        expect(current.tagName).toBe('SPAN');
    });

    it('renders nothing when list empty', () => {
        const { container } = render(<Breadcrumbs breadcrumbs={[]} />);
        expect(container).toBeEmptyDOMElement();
    });
});
