export const LANDING_PAGE_PREVIEW_PLACEHOLDER_URL = 'about:blank';
export const LANDING_PAGE_POPUP_BLOCKED_MESSAGE = 'Your browser blocked the landing page tab. Please allow pop-ups for ERNIE and try again.';

function applyNoReferrerPolicy(previewWindow: Window): void {
    try {
        const previewDocument = previewWindow.document;
        previewDocument.open();
        previewDocument.write(
            '<!doctype html><html><head><meta name="referrer" content="no-referrer"></head><body></body></html>',
        );
        previewDocument.close();
    } catch {
        // If the placeholder document cannot be touched, the tab can still be
        // closed or navigated; the opener reference is removed before this runs.
    }
}

export function openLandingPagePreviewPlaceholder(): Window | null {
    // Do not pass `noopener` here: browsers may return `null` for noopener
    // windows, and this flow needs the WindowProxy to navigate after async save.
    const previewWindow = window.open(LANDING_PAGE_PREVIEW_PLACEHOLDER_URL, '_blank');

    if (previewWindow) {
        previewWindow.opener = null;
        applyNoReferrerPolicy(previewWindow);
    }

    return previewWindow;
}
