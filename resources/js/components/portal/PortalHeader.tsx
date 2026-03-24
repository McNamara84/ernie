import { Link } from '@inertiajs/react';
import { Home, Menu, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { legalNotice, portal } from '@/routes';

interface NavItem {
    label: string;
    href: string;
    external: boolean;
    active?: boolean;
    icon?: React.ReactNode;
}

const NAV_ITEMS: NavItem[] = [
    { label: 'Home', href: 'https://dataservices.gfz.de/web', external: true, icon: <Home className="h-4 w-4" /> },
    { label: 'Find', href: portal().url, external: false, active: true },
    { label: 'Publish Data', href: 'https://dataservices.gfz.de/web/publish-data/publication-instructions', external: true },
    { label: 'Samples (IGSN)', href: 'https://dataservices.gfz.de/web/samples/introduction', external: true },
    { label: 'Support', href: 'https://dataservices.gfz.de/web/about-us', external: true },
    { label: 'About Us', href: 'https://dataservices.gfz.de/web/about-us', external: true },
    { label: 'Legal Notice', href: legalNotice().url, external: false },
    { label: 'Data Protection', href: 'https://dataservices.gfz.de/web/about-us/data-protection', external: true },
];

function NavLink({ item }: { item: NavItem }) {
    const baseClasses =
        'px-3 py-2 text-sm font-medium transition-colors hover:bg-portal-nav-active rounded-sm';
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
    const baseClasses =
        'block w-full px-4 py-3 text-sm font-medium transition-colors hover:bg-portal-nav-active';
    const activeClasses = item.active ? 'bg-portal-nav-active font-semibold' : '';
    const className = `${baseClasses} ${activeClasses}`.trim();
    const ariaCurrent = item.active ? ('page' as const) : undefined;

    if (item.external) {
        return (
            <a href={item.href} className={`flex items-center gap-2 text-portal-nav-foreground ${className}`} onClick={onClick} aria-current={ariaCurrent}>
                {item.icon}
                {item.label}
            </a>
        );
    }

    return (
        <Link href={item.href} className={`flex items-center gap-2 text-portal-nav-foreground ${className}`} onClick={onClick} aria-current={ariaCurrent}>
            {item.icon}
            {item.label}
        </Link>
    );
}

export function PortalHeader() {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    return (
        <header data-slot="portal-header">
            {/* Top Branding Bar */}
            <div className="bg-portal-header">
                <div className="flex h-16 items-center justify-between px-6">
                    <h1 className="text-xl font-semibold tracking-wide text-portal-header-foreground">
                        GFZ Data Services Portal
                    </h1>
                    <img
                        src="/images/gfz-logo_en.svg"
                        alt="GFZ Helmholtz Centre for Geosciences"
                        className="h-10"
                    />
                </div>
            </div>

            {/* Navigation Bar – Desktop */}
            <nav className="bg-portal-nav" aria-label="Portal navigation">
                <div className="flex items-center justify-between px-6">
                    {/* Desktop menu */}
                    <ul className="hidden items-center gap-1 py-1 md:flex">
                        {NAV_ITEMS.map((item) => (
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
                            {NAV_ITEMS.map((item) => (
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
