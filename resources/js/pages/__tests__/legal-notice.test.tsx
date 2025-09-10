import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import LegalNotice from '../legal-notice';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

describe('LegalNotice', () => {
    it('renders heading and GFZ link', () => {
        render(<LegalNotice />);
        expect(
            screen.getByRole('heading', { name: /legal information \/ provider identification/i }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /www\.gfz\.de/i })).toHaveAttribute(
            'href',
            'https://www.gfz.de/en/',
        );
    });
});

