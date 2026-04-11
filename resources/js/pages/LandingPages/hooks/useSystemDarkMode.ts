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
 * Reads the system color scheme preference without any side effects.
 *
 * Use this hook when a component needs to know the current preference
 * (e.g. to switch map tiles) but should not own the global dark-mode state.
 */
export function usePrefersDarkMode(): boolean {
    return useSyncExternalStore(
        useCallback(subscribe, []),
        getSnapshot,
        getServerSnapshot,
    );
}

/**
 * Applies the `.dark` class and `colorScheme` style to `document.documentElement`
 * based on system color scheme preference.
 *
 * Call this hook **once** at the landing page root (e.g. `default_gfz.tsx`).
 * Child components that need the dark-mode value should receive it as a prop
 * or use the side-effect-free {@link usePrefersDarkMode} hook instead.
 *
 * Cleans up the `.dark` class and `colorScheme` on unmount to avoid
 * interfering with other pages.
 */
export function useSystemDarkMode(): boolean {
    const isDark = usePrefersDarkMode();

    useEffect(() => {
        const el = document.documentElement;

        // Capture previous state so we can restore it on unmount
        const prevHadDark = el.classList.contains('dark');
        const prevColorScheme = el.style.colorScheme;

        if (isDark) {
            el.classList.add('dark');
            el.style.colorScheme = 'dark';
        } else {
            el.classList.remove('dark');
            el.style.colorScheme = 'light';
        }
        return () => {
            // Restore the state that existed before this hook took over
            el.classList.toggle('dark', prevHadDark);
            el.style.colorScheme = prevColorScheme;
        };
    }, [isDark]);

    return isDark;
}
