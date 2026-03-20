import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

import { AppFooter } from '@/components/app-footer';

/**
 * Full-width layout for the portal page.
 *
 * Similar to PublicLayout but without the max-width constraint,
 * allowing the portal to use the full viewport width.
 */
export default function PortalLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="border-b border-sidebar-border/80 bg-background">
                <nav className="flex h-16 w-full items-center px-6">
                    <Link href="/" className="text-lg font-semibold">
                        ERNIE
                    </Link>
                </nav>
            </header>
            <main className="flex flex-1 flex-col">{children}</main>
            <AppFooter />
        </div>
    );
}
