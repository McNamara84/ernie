import { DETACHED_TAB_PLACEHOLDER_URL, openDetachedTab } from '@/lib/detached-tab';

export const LANDING_PAGE_PREVIEW_PLACEHOLDER_URL = DETACHED_TAB_PLACEHOLDER_URL;
export const LANDING_PAGE_POPUP_BLOCKED_MESSAGE = 'Your browser blocked the landing page tab. Please allow pop-ups for ERNIE and try again.';

export function openLandingPagePreviewPlaceholder(): Window | null {
    return openDetachedTab();
}
