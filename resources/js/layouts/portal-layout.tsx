import { type PropsWithChildren } from 'react';

import { AppFooter } from '@/components/app-footer';
import { PageTransition } from '@/components/page-transition';
import { PortalHeader } from '@/components/portal/PortalHeader';
import { useNProgress } from '@/hooks/use-nprogress';

/**
 * Full-width layout for the portal page.
 *
 * Similar to PublicLayout but without the max-width constraint,
 * allowing the portal to use the full viewport width.
 */
export default function PortalLayout({ children }: PropsWithChildren) {
    useNProgress();

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <PortalHeader />
            <main className="flex flex-1 flex-col">
                <PageTransition>{children}</PageTransition>
            </main>
            <AppFooter />
        </div>
    );
}
