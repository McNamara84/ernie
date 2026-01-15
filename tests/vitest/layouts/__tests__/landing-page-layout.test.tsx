import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import LandingPageLayout from '@/layouts/LandingPageLayout';

// Mock Inertia's Head component
vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children: React.ReactNode }) => <head data-testid="head">{children}</head>,
}));

describe('LandingPageLayout', () => {
    it('renders children content', () => {
        render(
            <LandingPageLayout>
                <div data-testid="child-content">Test Content</div>
            </LandingPageLayout>
        );

        expect(screen.getByTestId('child-content')).toBeInTheDocument();
        expect(screen.getByText('Test Content')).toBeInTheDocument();
    });

    it('renders the footer with GFZ branding', () => {
        render(
            <LandingPageLayout>
                <div>Content</div>
            </LandingPageLayout>
        );

        expect(screen.getByText('GFZ Data Services')).toBeInTheDocument();
        expect(screen.getByText('GFZ German Research Centre for Geosciences')).toBeInTheDocument();
    });

    it('shows preview banner when isPreview is true', () => {
        render(
            <LandingPageLayout isPreview={true}>
                <div>Content</div>
            </LandingPageLayout>
        );

        expect(screen.getByText(/preview mode/i)).toBeInTheDocument();
        expect(screen.getByText(/this landing page is not publicly visible yet/i)).toBeInTheDocument();
    });

    it('does not show preview banner when isPreview is false', () => {
        render(
            <LandingPageLayout isPreview={false}>
                <div>Content</div>
            </LandingPageLayout>
        );

        expect(screen.queryByText(/preview mode/i)).not.toBeInTheDocument();
    });

    it('renders footer links', () => {
        render(
            <LandingPageLayout>
                <div>Content</div>
            </LandingPageLayout>
        );

        expect(screen.getByRole('link', { name: 'www.gfz-potsdam.de' })).toHaveAttribute(
            'href',
            'https://www.gfz-potsdam.de'
        );
        expect(screen.getByRole('link', { name: 'Data Services' })).toHaveAttribute(
            'href',
            'https://dataservices.gfz-potsdam.de'
        );
        expect(screen.getByRole('link', { name: 'Impressum' })).toHaveAttribute('href', '/imprint');
        expect(screen.getByRole('link', { name: 'Privacy Policy' })).toHaveAttribute('href', '/privacy');
    });

    it('includes copyright with current year', () => {
        render(
            <LandingPageLayout>
                <div>Content</div>
            </LandingPageLayout>
        );

        const currentYear = new Date().getFullYear();
        expect(screen.getByText(new RegExp(`© ${currentYear}`))).toBeInTheDocument();
    });

    it('has correct layout structure with main and footer', () => {
        render(
            <LandingPageLayout>
                <div>Content</div>
            </LandingPageLayout>
        );

        expect(screen.getByRole('main')).toBeInTheDocument();
        expect(screen.getByRole('contentinfo')).toBeInTheDocument(); // footer
    });

    it('opens external links in new tab with security attributes', () => {
        render(
            <LandingPageLayout>
                <div>Content</div>
            </LandingPageLayout>
        );

        const externalLink = screen.getByRole('link', { name: 'www.gfz-potsdam.de' });
        expect(externalLink).toHaveAttribute('target', '_blank');
        expect(externalLink).toHaveAttribute('rel', 'noopener noreferrer');
    });
});
