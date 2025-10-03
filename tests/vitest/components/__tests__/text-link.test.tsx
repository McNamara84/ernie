import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import TextLink from '@/components/text-link';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children?: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('TextLink', () => {
    it('renders anchor with href', () => {
        render(<TextLink href="/about">About</TextLink>);
        const link = screen.getByRole('link', { name: 'About' });
        expect(link).toHaveAttribute('href', '/about');
    });

    it('accepts custom className', () => {
        render(
            <TextLink href="/" className="custom">
                Home
            </TextLink>,
        );
        const link = screen.getByRole('link', { name: 'Home' });
        expect(link).toHaveClass('custom');
    });
});
