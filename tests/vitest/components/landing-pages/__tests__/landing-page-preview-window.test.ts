/**
 * @vitest-environment jsdom
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    LANDING_PAGE_PREVIEW_PLACEHOLDER_URL,
    openLandingPagePreviewPlaceholder,
} from '@/components/landing-pages/landing-page-preview-window';
import { DETACHED_TAB_PLACEHOLDER_URL } from '@/lib/detached-tab';

describe('landing-page-preview-window', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('opens a placeholder tab with a no-referrer policy and no opener reference', () => {
        const documentClose = vi.fn();
        const documentOpen = vi.fn();
        const documentWrite = vi.fn();
        const previewWindow = {
            close: vi.fn(),
            document: {
                close: documentClose,
                open: documentOpen,
                write: documentWrite,
            },
            location: { href: LANDING_PAGE_PREVIEW_PLACEHOLDER_URL },
            opener: { source: 'test-opener' },
        } as unknown as Window;
        const open = vi.fn().mockReturnValue(previewWindow);
        vi.stubGlobal('open', open);

        const result = openLandingPagePreviewPlaceholder();

        expect(LANDING_PAGE_PREVIEW_PLACEHOLDER_URL).toBe(DETACHED_TAB_PLACEHOLDER_URL);
        expect(open).toHaveBeenCalledWith(LANDING_PAGE_PREVIEW_PLACEHOLDER_URL, '_blank');
        expect(result).toBe(previewWindow);
        expect(documentOpen).toHaveBeenCalledOnce();
        expect(documentWrite).toHaveBeenCalledWith(expect.stringContaining('name="referrer"'));
        expect(documentWrite).toHaveBeenCalledWith(expect.stringContaining('content="no-referrer"'));
        expect(documentClose).toHaveBeenCalledOnce();
        expect(previewWindow.opener).toBeNull();
    });

    it('returns null when the browser blocks the placeholder tab', () => {
        const open = vi.fn().mockReturnValue(null);
        vi.stubGlobal('open', open);

        expect(openLandingPagePreviewPlaceholder()).toBeNull();
        expect(open).toHaveBeenCalledWith(LANDING_PAGE_PREVIEW_PLACEHOLDER_URL, '_blank');
    });

    it('still returns the placeholder tab when the no-referrer document cannot be written', () => {
        const previewWindow = {
            close: vi.fn(),
            get document() {
                throw new Error('Document access failed');
            },
            location: { href: LANDING_PAGE_PREVIEW_PLACEHOLDER_URL },
            opener: { source: 'test-opener' },
        } as unknown as Window;
        const open = vi.fn().mockReturnValue(previewWindow);
        vi.stubGlobal('open', open);

        const result = openLandingPagePreviewPlaceholder();

        expect(result).toBe(previewWindow);
        expect(previewWindow.opener).toBeNull();
    });
});
