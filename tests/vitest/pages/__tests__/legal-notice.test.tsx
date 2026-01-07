import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import LegalNotice from '@/pages/legal-notice';

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

    it('shows the updated responsible person', () => {
        render(<LegalNotice />);
        expect(screen.getByText(/lars-christian klinnert/i)).toBeInTheDocument();
    });
});

