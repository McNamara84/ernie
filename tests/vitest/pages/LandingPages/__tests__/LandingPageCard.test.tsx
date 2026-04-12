/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { LandingPageCard } from '@/pages/LandingPages/components/LandingPageCard';

describe('LandingPageCard', () => {
    it('renders children', () => {
        render(<LandingPageCard>Test content</LandingPageCard>);
        expect(screen.getByText('Test content')).toBeInTheDocument();
    });

    it('applies fade-in-on-scroll class', () => {
        render(<LandingPageCard data-testid="card">content</LandingPageCard>);
        expect(screen.getByTestId('card')).toHaveClass('fade-in-on-scroll');
    });

    it('renders as a section element', () => {
        render(<LandingPageCard data-testid="card">content</LandingPageCard>);
        expect(screen.getByTestId('card').tagName).toBe('SECTION');
    });

    it('applies data-slot attribute', () => {
        render(<LandingPageCard data-testid="card">content</LandingPageCard>);
        expect(screen.getByTestId('card')).toHaveAttribute('data-slot', 'landing-page-card');
    });

    it('merges custom className', () => {
        render(
            <LandingPageCard data-testid="card" className="custom-class">
                content
            </LandingPageCard>,
        );
        const card = screen.getByTestId('card');
        expect(card).toHaveClass('custom-class');
        expect(card).toHaveClass('fade-in-on-scroll');
    });

    it('passes through aria attributes', () => {
        render(
            <LandingPageCard aria-labelledby="heading-test" data-testid="card">
                content
            </LandingPageCard>,
        );
        expect(screen.getByTestId('card')).toHaveAttribute('aria-labelledby', 'heading-test');
    });
});
