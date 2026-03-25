import { type PropsWithChildren } from 'react';

import { AppFooter } from '@/components/app-footer';
import { PortalHeader } from '@/components/portal/PortalHeader';

/**
 * Full-width layout for the portal page.
 *
 * Similar to PublicLayout but without the max-width constraint,
 * allowing the portal to use the full viewport width.
 */
export default function PortalLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <PortalHeader />
            <main className="flex flex-1 flex-col">{children}</main>
            <AppFooter />
        </div>
    );
}
