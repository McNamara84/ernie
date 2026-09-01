import { Link } from '@inertiajs/react';
import { ChevronDown, Home, Menu, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { legalNotice } from '@/routes';
import type { PortalKind } from '@/types/portal';

interface NavItem {
    label: string;
    href: string;
    external: boolean;
    active?: boolean;
    icon?: React.ReactNode;
}

const NAV_ITEMS: NavItem[] = [
    { label: 'Home', href: 'https://dataservices.gfz-potsdam.de/web', external: true, icon: <Home className="h-4 w-4" /> },
    { label: 'Publish Data', href: 'https://dataservices.gfz-potsdam.de/web/publish-data/publication-instructions', external: true },
    { label: 'Samples (IGSN)', href: 'https://dataservices.gfz-potsdam.de/web/samples/introduction', external: true },
    { label: 'Support', href: 'https://dataservices.gfz-potsdam.de/web/about-us', external: true },
    { label: 'About Us', href: 'https://dataservices.gfz-potsdam.de/web/about-us', external: true },
    { label: 'Legal Notice', href: legalNotice().url, external: false },
    { label: 'Data Protection', href: 'https://dataservices.gfz-potsdam.de/web/about-us/data-protection', external: true },
];

const FIND_ITEMS: Array<NavItem & { kind: PortalKind }> = [
    { kind: 'doi', label: 'Data Portal', href: '/doi-search', external: false },
    { kind: 'igsn', label: 'IGSN Portal', href: '/igsn-search', external: false },
];

function NavLink({ item }: { item: NavItem }) {
    const baseClasses = 'px-3 py-2 text-sm font-medium transition-colors hover:bg-portal-nav-active rounded-sm';
    const activeClasses = item.active ? 'bg-portal-nav-active font-semibold' : '';
    const className = `${baseClasses} ${activeClasses}`.trim();
    const ariaCurrent = item.active ? ('page' as const) : undefined;

    if (item.external) {
        return (
            <a href={item.href} className={`flex items-center gap-1.5 text-portal-nav-foreground ${className}`} aria-current={ariaCurrent}>
                {item.icon}
                {item.label}
            </a>
        );
    }

    return (
        <Link href={item.href} className={`flex items-center gap-1.5 text-portal-nav-foreground ${className}`} aria-current={ariaCurrent}>
            {item.icon}
            {item.label}
        </Link>
    );
}

function MobileNavLink({ item, onClick }: { item: NavItem; onClick: () => void }) {
    const baseClasses = 'block w-full px-4 py-3 text-sm font-medium transition-colors hover:bg-portal-nav-active';
    const activeClasses = item.active ? 'bg-portal-nav-active font-semibold' : '';
    const className = `${baseClasses} ${activeClasses}`.trim();
    const ariaCurrent = item.active ? ('page' as const) : undefined;

    if (item.external) {
        return (
            <a
                href={item.href}
                className={`flex items-center gap-2 text-portal-nav-foreground ${className}`}
                onClick={onClick}
                aria-current={ariaCurrent}
            >
                {item.icon}
                {item.label}
            </a>
        );
    }

    return (
        <Link
            href={item.href}
            className={`flex items-center gap-2 text-portal-nav-foreground ${className}`}
            onClick={onClick}
            aria-current={ariaCurrent}
        >
            {item.icon}
            {item.label}
        </Link>
    );
}

export function PortalHeader({ portalKind = 'doi' }: { portalKind?: PortalKind }) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    return (
        <header data-slot="portal-header">
            {/* Top Branding Bar */}
            <div className="bg-portal-header">
                <div className="flex h-16 items-center justify-between px-6">
                    <h1
                        className="sr-only text-xl font-semibold tracking-wide text-portal-header-foreground md:not-sr-only"
                        data-testid="portal-wordmark"
                    >
                        GFZ Data Services Portal
                    </h1>
                    <img src="/images/gfz-logo_en.svg" alt="GFZ Helmholtz Centre for Geosciences" className="ml-auto h-10" />
                </div>
            </div>

            {/* Navigation Bar – Desktop */}
            <nav className="bg-portal-nav" aria-label="Portal navigation">
                <div className="flex items-center justify-between px-6">
                    {/* Desktop menu */}
                    <ul className="hidden items-center gap-1 py-1 md:flex">
                        <li>
                            <NavLink item={NAV_ITEMS[0]} />
                        </li>
                        <li>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        className="flex items-center gap-1 rounded-sm bg-portal-nav-active px-3 py-2 text-sm font-semibold text-portal-nav-foreground transition-colors hover:bg-portal-nav-active"
                                        aria-current="page"
                                    >
                                        Find
                                        <ChevronDown className="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start" className="min-w-44">
                                    {FIND_ITEMS.map((item) => (
                                        <DropdownMenuItem key={item.kind} asChild>
                                            <Link href={item.href} aria-current={portalKind === item.kind ? 'page' : undefined}>
                                                {item.label}
                                            </Link>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </li>
                        {NAV_ITEMS.slice(1).map((item) => (
                            <li key={item.label}>
                                <NavLink item={item} />
                            </li>
                        ))}
                    </ul>

                    {/* Mobile hamburger button */}
                    <div className="flex w-full items-center justify-between py-2 md:hidden">
                        <span className="text-sm font-semibold text-portal-nav-foreground">Menu</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => setMobileMenuOpen((prev) => !prev)}
                            aria-expanded={mobileMenuOpen}
                            aria-label={mobileMenuOpen ? 'Close menu' : 'Open menu'}
                            className="text-portal-nav-foreground hover:bg-portal-nav-active"
                        >
                            {mobileMenuOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
                        </Button>
                    </div>
                </div>

                {/* Mobile menu dropdown */}
                {mobileMenuOpen && (
                    <div className="border-t border-portal-nav-active md:hidden" data-testid="mobile-menu">
                        <ul className="py-1">
                            <li>
                                <MobileNavLink item={NAV_ITEMS[0]} onClick={() => setMobileMenuOpen(false)} />
                            </li>
                            <li>
                                <span className="block px-4 pt-3 pb-1 text-xs font-semibold tracking-wide text-portal-nav-foreground/75 uppercase">
                                    Find
                                </span>
                                <ul>
                                    {FIND_ITEMS.map((item) => (
                                        <li key={item.kind}>
                                            <MobileNavLink
                                                item={{ ...item, active: portalKind === item.kind }}
                                                onClick={() => setMobileMenuOpen(false)}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </li>
                            {NAV_ITEMS.slice(1).map((item) => (
                                <li key={item.label}>
                                    <MobileNavLink item={item} onClick={() => setMobileMenuOpen(false)} />
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </nav>
        </header>
    );
}
