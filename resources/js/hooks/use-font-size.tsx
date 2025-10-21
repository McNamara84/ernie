import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import { type FontSize, type SharedData } from '@/types';

/**
 * Initialize font size on page load
 */
export function initializeFontSize(fontSize: FontSize): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('font-large', fontSize === 'large');
}

/**
 * Hook to manage user's font size preference
 */
export function useFontSize() {
    const { fontSizePreference } = usePage<SharedData>().props;
    const [fontSize, setFontSize] = useState<FontSize>(fontSizePreference);

    const updateFontSize = useCallback((size: FontSize): void => {
        setFontSize(size);
        document.documentElement.classList.toggle('font-large', size === 'large');

        // Persist to backend
        router.put(
            '/settings/font-size',
            {
                font_size_preference: size,
            },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    }, []);

    useEffect(() => {
        initializeFontSize(fontSizePreference);
    }, [fontSizePreference]);

    return { fontSize, updateFontSize } as const;
}
