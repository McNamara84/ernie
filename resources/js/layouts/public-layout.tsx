import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

import { AppFooter } from '@/components/app-footer';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';

export default function PublicLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<SharedData>().props;
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="border-b border-sidebar-border/80 bg-background">
                <nav className="mx-auto flex h-16 w-full max-w-4xl items-center justify-end gap-4 px-6">
                    {auth.user ? (
                        <Link href={dashboard.url()} className="rounded-sm border px-5 py-1.5">
                            Dashboard
                        </Link>
                    ) : (
                        <Link href={login.url()} className="rounded-sm border px-5 py-1.5">
                            Log in
                        </Link>
                    )}
                </nav>
            </header>
            <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col px-6 py-12">{children}</main>
            <AppFooter />
        </div>
    );
}
