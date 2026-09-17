import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { PortalHeader } from '@/components/portal/PortalHeader';
import { useNProgress } from '@/hooks/use-nprogress';

export default function HomeLayout({ children }: PropsWithChildren) {
    useNProgress();

    return (
        <div className="flex min-h-dvh flex-col bg-background text-foreground">
            <a href="#home-content" className="sr-only z-50 rounded bg-background p-3 text-foreground focus:not-sr-only focus:absolute">
                Skip to content
            </a>
            <PortalHeader portalKind="home" />
            <main id="home-content" className="flex-1">
                {children}
            </main>
            <footer className="border-t bg-portal-header px-6 py-8 text-sm text-portal-header-foreground">
                <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-5 sm:flex-row">
                    <p>© GFZ Helmholtz Centre for Geosciences</p>
                    <nav aria-label="Footer navigation" className="flex flex-wrap justify-center gap-x-6 gap-y-3">
                        <Link href="/legal-notice" className="hover:underline">
                            Legal Notice
                        </Link>
                        <a href="https://dataservices.gfz-potsdam.de/web/about-us/data-protection" className="hover:underline">
                            Data Protection
                        </a>
                        <a href="https://dataservices.gfz-potsdam.de/web/about-us/copyrights" className="hover:underline">
                            Copyrights
                        </a>
                    </nav>
                </div>
            </footer>
        </div>
    );
}
