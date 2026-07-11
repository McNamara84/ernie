export const DETACHED_TAB_PLACEHOLDER_URL = 'about:blank';

function applyNoReferrerPolicy(detachedWindow: Window): void {
    try {
        const detachedDocument = detachedWindow.document;
        detachedDocument.open();
        detachedDocument.write('<!doctype html><html><head><meta name="referrer" content="no-referrer"></head><body></body></html>');
        detachedDocument.close();
    } catch {
        // The tab remains detached and navigable even if its placeholder
        // document cannot be accessed.
    }
}

export function openDetachedTab(targetUrl?: string): Window | null {
    // Passing `noopener` would make a successfully opened tab and a blocked
    // popup indistinguishable in standards-compliant browsers.
    const detachedWindow = window.open(DETACHED_TAB_PLACEHOLDER_URL, '_blank');

    if (!detachedWindow) {
        return null;
    }

    detachedWindow.opener = null;
    applyNoReferrerPolicy(detachedWindow);

    if (targetUrl !== undefined) {
        detachedWindow.location.href = targetUrl;
    }

    return detachedWindow;
}
