import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import About from '@/pages/about';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

describe('About', () => {
    it('renders heading and GitHub link', () => {
        render(<About />);
        expect(screen.getByRole('heading', { name: /about ernie/i })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /github/i })).toHaveAttribute(
            'href',
            'https://github.com/McNamara84/ernie',
        );
    });
});

