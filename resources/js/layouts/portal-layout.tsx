import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

import { AppFooter } from '@/components/app-footer';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';

/**
 * Full-width layout for the portal page.
 *
 * Similar to PublicLayout but without the max-width constraint,
 * allowing the portal to use the full viewport width.
 */
export default function PortalLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<SharedData>().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="border-b border-sidebar-border/80 bg-background">
                <nav className="flex h-16 w-full items-center justify-between px-6">
                    <Link href="/" className="text-lg font-semibold">
                        ERNIE
                    </Link>
                    <div className="flex items-center gap-4">
                        {auth.user ? (
                            <Link href={dashboard()} className="rounded-sm border px-5 py-1.5 hover:bg-accent">
                                Dashboard
                            </Link>
                        ) : (
                            <Link href={login()} className="rounded-sm border px-5 py-1.5 hover:bg-accent">
                                Log in
                            </Link>
                        )}
                    </div>
                </nav>
            </header>
            <main className="flex flex-1 flex-col">{children}</main>
            <AppFooter />
        </div>
    );
}
