import { useCallback, useSyncExternalStore } from 'react';

const QUERY = '(prefers-reduced-motion: reduce)';

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
 * Returns `true` when the user prefers reduced motion (OS-level setting).
 * Reactively updates when the OS preference changes.
 */
export function useReducedMotion(): boolean {
    return useSyncExternalStore(
        useCallback(subscribe, []),
        getSnapshot,
        getServerSnapshot,
    );
}
