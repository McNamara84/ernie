import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import TextLink from '../text-link';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href, className }: { children?: React.ReactNode; href: string; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

describe('TextLink', () => {
    it('renders a link with correct href and classes', () => {
        render(<TextLink href="/test">Test</TextLink>);
        const link = screen.getByRole('link', { name: /test/i });
        expect(link).toHaveAttribute('href', '/test');
        expect(link).toHaveClass('underline-offset-4');
    });
});

