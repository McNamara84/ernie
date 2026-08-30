import { type PropsWithChildren } from 'react';

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
        <div className="flex h-dvh max-h-dvh flex-col overflow-hidden bg-background text-foreground">
            <div className="shrink-0">
                <PortalHeader />
            </div>
            <main className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageTransition>{children}</PageTransition>
            </main>
        </div>
    );
}
