import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type PropsWithChildren } from 'react';

import { AppFooter } from '@/components/app-footer';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { changelog as changelogRoute, dashboard, portal } from '@/routes';
import { type SharedData } from '@/types';

export default function ChangelogLayout({ children }: PropsWithChildren) {
    const { props: { auth }, url: currentUrl } = usePage<SharedData>();

    const navLinks = [
        { label: 'Portal', href: portal().url },
        { label: 'Changelog', href: changelogRoute().url },
    ];

    return (
        <div data-slot="changelog-layout" className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="border-b border-sidebar-border/80 bg-background">
                <nav className="mx-auto flex h-16 w-full max-w-4xl items-center justify-between px-6">
                    <Button variant="ghost" size="sm" asChild>
                        {auth.user ? (
                            <Link href={dashboard().url}>
                                <ArrowLeft className="mr-1.5 h-4 w-4" />
                                Back to Dashboard
                            </Link>
                        ) : (
                            <Link href={portal().url}>
                                <ArrowLeft className="mr-1.5 h-4 w-4" />
                                Back to Portal
                            </Link>
                        )}
                    </Button>

                    <div className="flex items-center gap-1">
                        {navLinks.map((link) => (
                            <Button
                                key={link.href}
                                variant="ghost"
                                size="sm"
                                asChild
                                className={cn(currentUrl.startsWith(link.href) && 'bg-accent font-medium')}
                            >
                                <Link href={link.href}>{link.label}</Link>
                            </Button>
                        ))}
                    </div>
                </nav>
            </header>
            <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col px-6 py-12">{children}</main>
            <AppFooter />
        </div>
    );
}
