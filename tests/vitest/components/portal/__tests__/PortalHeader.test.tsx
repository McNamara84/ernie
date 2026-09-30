import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen, within } from '@tests/vitest/utils/render';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} data-testid="inertia-link" {...props}>
            {children}
        </a>
    ),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({
        children,
        variant = 'default',
        size = 'default',
        ...props
    }: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: string; size?: string }) => (
        <button data-slot="button" data-variant={variant} data-size={size} {...props}>
            {children}
        </button>
    ),
}));

import { PortalHeader } from '@/components/portal/PortalHeader';

describe('PortalHeader', () => {
    it.each([
        ['home', 'Home'],
        ['find', 'Overview'],
        ['data-centres', 'Data Centres'],
        ['data-centres-description', null],
        ['doi', 'Data Portal'],
        ['igsn', 'IGSN Portal'],
    ] as const)('keeps Overview first and marks only the current link for %s on desktop and mobile', async (portalKind, currentLabel) => {
        const user = userEvent.setup();
        render(<PortalHeader portalKind={portalKind} />);
        const entries = [
            ['Overview', '/find'],
            ['Data Portal', '/doi-search'],
            ['Data Centres', '/data-centres'],
            ['IGSN Portal', '/igsn-search'],
        ];

        await user.click(screen.getByRole('button', { name: 'Find' }));
        const desktopLinks = await screen.findAllByRole('menuitem');
        expect(desktopLinks.map((link) => link.textContent)).toEqual(entries.map(([label]) => label));
        for (const [index, [label, href]] of entries.entries()) {
            const link = desktopLinks[index];
            expect(link).toHaveAttribute('href', href);
            expect(link).toHaveAttribute('data-testid', 'inertia-link');
            expect(link.getAttribute('aria-current')).toBe(label === currentLabel ? 'page' : null);
        }
        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('button', { name: 'Open menu' }));
        const mobile = within(screen.getByTestId('mobile-menu'));
        expect(
            mobile
                .getAllByRole('link')
                .slice(1, 5)
                .map((link) => link.textContent),
        ).toEqual(entries.map(([label]) => label));
        for (const [label, href] of [['Home', '/'], ...entries]) {
            const link = mobile.getByRole('link', { name: label });
            expect(link).toHaveAttribute('href', href);
            expect(link.getAttribute('aria-current')).toBe(label === currentLabel ? 'page' : null);
        }
        await user.click(mobile.getByRole('link', { name: 'Overview' }));
        expect(screen.queryByTestId('mobile-menu')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Open menu' })).toHaveAttribute('aria-expanded', 'false');
    });

    it.each(['find', 'data-centres', 'data-centres-description'] as const)('keeps the %s wordmark visible without adding a second main heading', (kind) => {
        render(<PortalHeader portalKind={kind} />);
        expect(screen.getByTestId('portal-wordmark').tagName).toBe('P');
        expect(screen.getByTestId('portal-wordmark')).toHaveTextContent('GFZ Data Services');
        expect(screen.getByTestId('portal-wordmark')).not.toHaveClass('sr-only');
        expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Home' })).not.toHaveAttribute('aria-current');
        expect(screen.getByRole('button', { name: 'Find' })).toHaveClass('bg-portal-nav-active');
    });

    it('highlights the Data Centres section on its description page without marking the overview as the current page', async () => {
        const user = userEvent.setup();
        render(<PortalHeader portalKind="data-centres-description" />);
        await user.click(screen.getByRole('button', { name: 'Find' }));
        const desktop = await screen.findByRole('menuitem', { name: 'Data Centres' });
        expect(desktop).toHaveClass('bg-portal-nav-active');
        expect(desktop).not.toHaveAttribute('aria-current');
        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('button', { name: 'Open menu' }));
        const mobile = within(screen.getByTestId('mobile-menu')).getByRole('link', { name: 'Data Centres' });
        expect(mobile).toHaveClass('bg-portal-nav-active');
        expect(mobile).not.toHaveAttribute('aria-current');
    });

    it('marks Home instead of Find on the homepage in desktop and mobile navigation', async () => {
        const user = userEvent.setup();
        render(<PortalHeader portalKind="home" />);
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('GFZ Data Services');
        expect(screen.getByRole('heading', { level: 1 })).not.toHaveClass('sr-only');
        expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('button', { name: 'Find' })).not.toHaveAttribute('aria-current');
        await user.click(screen.getByRole('button', { name: 'Find' }));
        expect(await screen.findByRole('menuitem', { name: 'Data Portal' })).not.toHaveAttribute('aria-current');
        expect(screen.getByRole('menuitem', { name: 'IGSN Portal' })).not.toHaveAttribute('aria-current');
        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('button', { name: 'Open menu' }));
        const mobile = within(screen.getByTestId('mobile-menu'));
        expect(mobile.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/');
        expect(mobile.getByRole('link', { name: 'Home' })).toHaveAttribute('aria-current', 'page');
        expect(mobile.getByRole('link', { name: 'Data Portal' })).not.toHaveAttribute('aria-current');
    });

    describe('branding bar', () => {
        it('renders portal title as h1 heading', () => {
            render(<PortalHeader />);
            const heading = screen.getByRole('heading', { level: 1 });
            expect(heading).toBeInTheDocument();
            expect(heading).toHaveTextContent('GFZ Data Services Portal');
            expect(heading).toHaveClass('sr-only', 'md:not-sr-only');
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

        it('uses an internal link for Home and external links for TYPO3 pages', () => {
            render(<PortalHeader />);
            const homeLink = screen.getByText('Home').closest('a');
            expect(homeLink).toHaveAttribute('href', '/');
            expect(homeLink).toHaveAttribute('data-testid', 'inertia-link');
            expect(screen.getByText('Publish Data').closest('a')).not.toHaveAttribute('data-testid', 'inertia-link');
        });

        it('uses Inertia Link for internal items', async () => {
            const user = userEvent.setup();
            render(<PortalHeader />);
            await user.click(screen.getByRole('button', { name: /find/i }));

            const dataPortalLink = await screen.findByRole('menuitem', { name: 'Data Portal' });
            const igsnPortalLink = screen.getByRole('menuitem', { name: 'IGSN Portal' });
            expect(dataPortalLink).toHaveAttribute('href', '/doi-search');
            expect(dataPortalLink).toHaveAttribute('data-testid', 'inertia-link');
            expect(igsnPortalLink).toHaveAttribute('href', '/igsn-search');
            expect(igsnPortalLink).toHaveAttribute('data-testid', 'inertia-link');

            const legalLink = screen.getByText('Legal Notice').closest('a');
            expect(legalLink).toHaveAttribute('href', '/legal-notice');
            expect(legalLink).toHaveAttribute('data-testid', 'inertia-link');
        });

        it('highlights the active Find item', () => {
            render(<PortalHeader />);
            const findButton = screen.getByRole('button', { name: /find/i });
            expect(findButton.className).toContain('bg-portal-nav-active');
            expect(findButton.className).toContain('font-semibold');
            expect(findButton).toHaveAttribute('aria-current', 'page');
            expect(findButton).toHaveAttribute('data-slot', 'dropdown-menu-trigger');
            expect(findButton).toHaveAttribute('data-variant', 'ghost');
        });

        it('marks the current portal submenu item', async () => {
            const user = userEvent.setup();
            render(<PortalHeader portalKind="igsn" />);
            await user.click(screen.getByRole('button', { name: /find/i }));

            expect(await screen.findByRole('menuitem', { name: 'IGSN Portal' })).toHaveAttribute('aria-current', 'page');
            expect(screen.getByRole('menuitem', { name: 'Data Portal' })).not.toHaveAttribute('aria-current');
        });

        it('does not set aria-current on inactive items', () => {
            render(<PortalHeader />);
            const homeLink = screen.getByText('Home').closest('a');
            expect(homeLink).not.toHaveAttribute('aria-current');
        });

        it('has correct external link targets', () => {
            render(<PortalHeader />);
            expect(screen.getByText('Publish Data').closest('a')).toHaveAttribute(
                'href',
                'https://dataservices.gfz-potsdam.de/web/publish-data/publication-instructions',
            );
            expect(screen.getByText('Samples (IGSN)').closest('a')).toHaveAttribute(
                'href',
                'https://dataservices.gfz-potsdam.de/web/samples/introduction',
            );
            expect(screen.getByText('Data Protection').closest('a')).toHaveAttribute(
                'href',
                'https://dataservices.gfz-potsdam.de/web/about-us/data-protection',
            );
        });
    });

    describe('mobile menu', () => {
        it('associates the menu with its trigger and restores focus after Escape', async () => {
            const user = userEvent.setup();
            render(<PortalHeader />);
            await user.click(screen.getByRole('button', { name: 'Open menu' }));
            const menu = screen.getByTestId('mobile-menu');
            expect(screen.getByRole('button', { name: 'Close menu' })).toHaveAttribute('aria-controls', menu.id);
            within(menu).getByRole('link', { name: 'Home' }).focus();
            await user.keyboard('{Escape}');
            expect(screen.queryByTestId('mobile-menu')).not.toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Open menu' })).toHaveFocus();
            expect(screen.getByRole('button', { name: 'Open menu' })).toHaveAttribute('aria-expanded', 'false');
        });

        it('does not show mobile menu by default', () => {
            render(<PortalHeader />);
            // Hamburger button is present
            expect(screen.getByLabelText('Open menu')).toBeInTheDocument();
            // Mobile dropdown is NOT in the DOM
            expect(screen.queryByTestId('mobile-menu')).not.toBeInTheDocument();
        });

        it('opens mobile menu on hamburger click', () => {
            render(<PortalHeader />);
            const menuButton = screen.getByLabelText('Open menu');
            fireEvent.click(menuButton);

            // After opening, button label changes
            expect(screen.getByLabelText('Close menu')).toBeInTheDocument();
            const mobileMenu = screen.getByTestId('mobile-menu');
            expect(within(mobileMenu).getByRole('link', { name: 'Data Portal' })).toHaveAttribute('href', '/doi-search');
            expect(within(mobileMenu).getByRole('link', { name: 'IGSN Portal' })).toHaveAttribute('href', '/igsn-search');
        });

        it.each(['Home', 'Publish Data', 'Legal Notice'])('closes mobile menu when following %s', (label) => {
            render(<PortalHeader />);
            const menuButton = screen.getByLabelText('Open menu');
            fireEvent.click(menuButton);

            // Click a mobile nav item within the mobile dropdown
            const mobileMenu = screen.getByTestId('mobile-menu');
            const link = within(mobileMenu).getByRole('link', { name: label });
            // jsdom cannot navigate to another document; still exercise the click handler.
            link.addEventListener('click', (event) => event.preventDefault());
            fireEvent.click(link);

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
