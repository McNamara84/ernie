/**
 * @vitest-environment jsdom
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DETACHED_TAB_PLACEHOLDER_URL, openDetachedTab } from '@/lib/detached-tab';

interface MockDetachedWindow {
    documentClose: ReturnType<typeof vi.fn>;
    documentOpen: ReturnType<typeof vi.fn>;
    documentWrite: ReturnType<typeof vi.fn>;
    window: Window;
}

function createDetachedWindow(): MockDetachedWindow {
    const documentClose = vi.fn();
    const documentOpen = vi.fn();
    const documentWrite = vi.fn();
    const detachedWindow = {
        document: {
            close: documentClose,
            open: documentOpen,
            write: documentWrite,
        },
        location: { href: DETACHED_TAB_PLACEHOLDER_URL },
        opener: { source: 'test-opener' },
    } as unknown as Window;

    return { documentClose, documentOpen, documentWrite, window: detachedWindow };
}

describe('openDetachedTab', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('opens an about:blank tab without a feature string and secures its placeholder document', () => {
        const detachedWindow = createDetachedWindow();
        const open = vi.fn().mockReturnValue(detachedWindow.window);
        vi.stubGlobal('open', open);

        const result = openDetachedTab();

        expect(open).toHaveBeenCalledWith(DETACHED_TAB_PLACEHOLDER_URL, '_blank');
        expect(result).toBe(detachedWindow.window);
        expect(detachedWindow.window.opener).toBeNull();
        expect(detachedWindow.documentOpen).toHaveBeenCalledOnce();
        expect(detachedWindow.documentWrite).toHaveBeenCalledWith(expect.stringContaining('name="referrer"'));
        expect(detachedWindow.documentWrite).toHaveBeenCalledWith(expect.stringContaining('content="no-referrer"'));
        expect(detachedWindow.documentClose).toHaveBeenCalledOnce();
        expect(detachedWindow.window.location.href).toBe(DETACHED_TAB_PLACEHOLDER_URL);
    });

    it('navigates the secured tab to an immediate target URL', () => {
        const detachedWindow = createDetachedWindow();
        const open = vi.fn().mockReturnValue(detachedWindow.window);
        vi.stubGlobal('open', open);

        const result = openDetachedTab('/editor?resourceId=42');

        expect(result).toBe(detachedWindow.window);
        expect(detachedWindow.window.opener).toBeNull();
        expect(detachedWindow.documentWrite).toHaveBeenCalledOnce();
        expect(detachedWindow.window.location.href).toBe('/editor?resourceId=42');
    });

    it('returns null when the browser blocks the placeholder tab', () => {
        const open = vi.fn().mockReturnValue(null);
        vi.stubGlobal('open', open);

        expect(openDetachedTab('/editor?resourceId=42')).toBeNull();
        expect(open).toHaveBeenCalledWith(DETACHED_TAB_PLACEHOLDER_URL, '_blank');
    });

    it('still detaches and navigates the tab when its placeholder document cannot be accessed', () => {
        const location = { href: DETACHED_TAB_PLACEHOLDER_URL };
        const detachedWindow = {
            get document() {
                throw new Error('Document access failed');
            },
            location,
            opener: { source: 'test-opener' },
        } as unknown as Window;
        const open = vi.fn().mockReturnValue(detachedWindow);
        vi.stubGlobal('open', open);

        const result = openDetachedTab('/editor?resourceId=42');

        expect(result).toBe(detachedWindow);
        expect(detachedWindow.opener).toBeNull();
        expect(location.href).toBe('/editor?resourceId=42');
    });
});
