import { useCallback, useEffect, useSyncExternalStore } from 'react';

const QUERY = '(prefers-color-scheme: dark)';

function getSnapshot(): boolean {
    return window.matchMedia(QUERY).matches;
}

function getServerSnapshot(): boolean {
    return false;
}

function subscribe(callback: () => void): () => void {
    const mediaQuery = window.matchMedia(QUERY);
    mediaQuery.addEventListener('change', callback);
    return () => mediaQuery.removeEventListener('change', callback);
}

/**
 * Applies the `.dark` class to `document.documentElement` based on system color scheme preference.
 *
 * Used on landing pages where the authenticated app layout's dark mode toggle is not available.
 * Reactively updates when the OS preference changes.
 * Cleans up the `.dark` class on unmount to avoid interfering with other pages.
 */
export function useSystemDarkMode(): boolean {
    const isDark = useSyncExternalStore(
        useCallback(subscribe, []),
        getSnapshot,
        getServerSnapshot,
    );

    useEffect(() => {
        if (isDark) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        return () => {
            document.documentElement.classList.remove('dark');
        };
    }, [isDark]);

    return isDark;
}
