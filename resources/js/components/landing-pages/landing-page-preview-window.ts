export const LANDING_PAGE_PREVIEW_PLACEHOLDER_URL = 'about:blank';
export const LANDING_PAGE_POPUP_BLOCKED_MESSAGE = 'Your browser blocked the landing page tab. Please allow pop-ups for ERNIE and try again.';

export function openLandingPagePreviewPlaceholder(): Window | null {
    // Do not pass `noopener` here: browsers may return `null` for noopener
    // windows, and this flow needs the WindowProxy to navigate after async save.
    const previewWindow = window.open(LANDING_PAGE_PREVIEW_PLACEHOLDER_URL, '_blank');

    if (previewWindow) {
        previewWindow.opener = null;
    }

    return previewWindow;
}
