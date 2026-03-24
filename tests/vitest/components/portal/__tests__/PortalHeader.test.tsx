import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@tests/vitest/utils/render';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} data-testid="inertia-link" {...props}>{children}</a>
    ),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & Record<string, unknown>) => (
        <button {...props}>{children}</button>
    ),
}));

import { PortalHeader } from '@/components/portal/PortalHeader';

describe('PortalHeader', () => {
    describe('branding bar', () => {
        it('renders portal title as h1 heading', () => {
            render(<PortalHeader />);
            const heading = screen.getByRole('heading', { level: 1 });
            expect(heading).toBeInTheDocument();
            expect(heading).toHaveTextContent('GFZ Data Services Portal');
        });

        it('renders GFZ logo', () => {
            render(<PortalHeader />);
            const logo = screen.getByAltText('GFZ Helmholtz Centre for Geosciences');
            expect(logo).toBeInTheDocument();
            expect(logo).toHaveAttribute('src', '/images/gfz-logo_en.svg');
        });
    });

    describe('desktop navigation', () => {
        it('renders all navigation items', () => {
            render(<PortalHeader />);
            expect(screen.getByText('Home')).toBeInTheDocument();
            expect(screen.getByText('Find')).toBeInTheDocument();
            expect(screen.getByText('Publish Data')).toBeInTheDocument();
            expect(screen.getByText('Samples (IGSN)')).toBeInTheDocument();
            expect(screen.getByText('Support')).toBeInTheDocument();
            expect(screen.getByText('About Us')).toBeInTheDocument();
            expect(screen.getByText('Legal Notice')).toBeInTheDocument();
            expect(screen.getByText('Data Protection')).toBeInTheDocument();
        });

        it('uses external links for external items', () => {
            render(<PortalHeader />);
            const homeLink = screen.getByText('Home').closest('a');
            expect(homeLink).toHaveAttribute('href', 'https://dataservices.gfz.de/web');
            expect(homeLink).not.toHaveAttribute('data-testid', 'inertia-link');
        });

        it('uses Inertia Link for internal items', () => {
            render(<PortalHeader />);
            const findLink = screen.getByText('Find').closest('a');
            expect(findLink).toHaveAttribute('href', '/portal');
            expect(findLink).toHaveAttribute('data-testid', 'inertia-link');

            const legalLink = screen.getByText('Legal Notice').closest('a');
            expect(legalLink).toHaveAttribute('href', '/legal-notice');
            expect(legalLink).toHaveAttribute('data-testid', 'inertia-link');
        });

        it('highlights the active Find item', () => {
            render(<PortalHeader />);
            const findLink = screen.getByText('Find').closest('a');
            expect(findLink?.className).toContain('bg-portal-nav-active');
            expect(findLink?.className).toContain('font-semibold');
            expect(findLink).toHaveAttribute('aria-current', 'page');
        });

        it('does not set aria-current on inactive items', () => {
            render(<PortalHeader />);
            const homeLink = screen.getByText('Home').closest('a');
            expect(homeLink).not.toHaveAttribute('aria-current');
        });

        it('has correct external link targets', () => {
            render(<PortalHeader />);
            expect(screen.getByText('Publish Data').closest('a')).toHaveAttribute(
                'href', 'https://dataservices.gfz.de/web/publish-data/publication-instructions',
            );
            expect(screen.getByText('Samples (IGSN)').closest('a')).toHaveAttribute(
                'href', 'https://dataservices.gfz.de/web/samples/introduction',
            );
            expect(screen.getByText('Data Protection').closest('a')).toHaveAttribute(
                'href', 'https://dataservices.gfz.de/web/about-us/data-protection',
            );
        });
    });

    describe('mobile menu', () => {
        it('does not show mobile menu by default', () => {
            render(<PortalHeader />);
            // Mobile menu items are rendered in the desktop nav too,
            // but the mobile dropdown should not be visible
            expect(screen.getByLabelText('Open menu')).toBeInTheDocument();
        });

        it('opens mobile menu on hamburger click', () => {
            render(<PortalHeader />);
            const menuButton = screen.getByLabelText('Open menu');
            fireEvent.click(menuButton);

            // After opening, button label changes
            expect(screen.getByLabelText('Close menu')).toBeInTheDocument();
        });

        it('closes mobile menu on item click', () => {
            render(<PortalHeader />);
            const menuButton = screen.getByLabelText('Open menu');
            fireEvent.click(menuButton);

            // Click a mobile nav item within the mobile dropdown
            const mobileMenu = screen.getByTestId('mobile-menu');
            const homeLink = within(mobileMenu).getByText('Home');
            fireEvent.click(homeLink);

            // Menu should close, button label back to "Open menu"
            expect(screen.getByLabelText('Open menu')).toBeInTheDocument();
        });

        it('toggles mobile menu closed on second click', () => {
            render(<PortalHeader />);
            const menuButton = screen.getByLabelText('Open menu');

            // Open
            fireEvent.click(menuButton);
            expect(screen.getByLabelText('Close menu')).toBeInTheDocument();

            // Close
            fireEvent.click(screen.getByLabelText('Close menu'));
            expect(screen.getByLabelText('Open menu')).toBeInTheDocument();
        });
    });

    describe('accessibility', () => {
        it('has navigation landmark with label', () => {
            render(<PortalHeader />);
            expect(screen.getByRole('navigation', { name: 'Portal navigation' })).toBeInTheDocument();
        });

        it('hamburger button has aria-expanded', () => {
            render(<PortalHeader />);
            const menuButton = screen.getByLabelText('Open menu');
            expect(menuButton).toHaveAttribute('aria-expanded', 'false');

            fireEvent.click(menuButton);
            expect(screen.getByLabelText('Close menu')).toHaveAttribute('aria-expanded', 'true');
        });
    });
});
