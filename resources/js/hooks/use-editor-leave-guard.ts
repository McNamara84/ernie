import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';

type EditorVisit = {
    url: URL | string;
    method: string;
    prefetch?: boolean;
    only?: unknown[];
    except?: unknown[];
    reset?: unknown[];
};

function destinationKey(url: URL | string, base: string): string {
    const destination = new URL(url.toString(), base);
    return destination.origin + destination.pathname + destination.search;
}

export function shouldWarnForEditorVisit(currentHref: string, visit: EditorVisit): boolean {
    if (visit.prefetch) return false;

    const current = new URL(currentHref, 'http://localhost');
    const destination = new URL(visit.url.toString(), current);
    if (destination.origin !== current.origin) return false;
    if (destinationKey(destination, current.href) === destinationKey(current, current.href)) return false;

    // A partial reload updates props of the same page without discarding its form.
    const sameEditorDocument =
        destination.pathname === current.pathname &&
        ['resourceId', 'xmlSession', 'jsonSession'].every((key) => destination.searchParams.get(key) === current.searchParams.get(key));
    if (sameEditorDocument && ((visit.only?.length ?? 0) > 0 || (visit.except?.length ?? 0) > 0 || (visit.reset?.length ?? 0) > 0)) {
        return false;
    }

    return true;
}

export function useEditorLeaveGuard(hasUnsavedChanges: boolean) {
    const dirtyRef = useRef(hasUnsavedChanges);
    const approvedDestinationRef = useRef<string | null>(null);
    dirtyRef.current = hasUnsavedChanges;

    const allowNextInternalVisit = useCallback((url: string) => {
        const key = destinationKey(url, window.location.href);
        approvedDestinationRef.current = key;
        queueMicrotask(() => {
            if (approvedDestinationRef.current === key) approvedDestinationRef.current = null;
        });
    }, []);

    useEffect(() => {
        if (!hasUnsavedChanges) return;

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            if (!dirtyRef.current) return;
            event.preventDefault();
            event.returnValue = '';
        };
        const removeBeforeVisit = router.on('before', (event) => {
            if (!dirtyRef.current || !shouldWarnForEditorVisit(window.location.href, event.detail.visit)) return;
            const key = destinationKey(event.detail.visit.url, window.location.href);
            if (approvedDestinationRef.current === key) {
                approvedDestinationRef.current = null;
                return;
            }
            if (!window.confirm('You have unsaved changes. Leave the editor?')) return false;
        });

        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => {
            removeBeforeVisit();
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [hasUnsavedChanges]);

    return { allowNextInternalVisit };
}
