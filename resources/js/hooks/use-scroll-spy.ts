import { useEffect, useState } from 'react';

/**
 * Hook for scroll-spy functionality.
 * Tracks which section is currently visible in the viewport.
 *
 * @param sectionIds - Array of section IDs to observe
 * @param rootMargin - Margin around the root element (default: '-80px 0px -80% 0px')
 * @returns The ID of the currently active section, or null if none
 *
 * @remarks
 * **Important:** The `sectionIds` array should be memoized (e.g., using `useMemo`)
 * to prevent unnecessary effect re-runs. Since React compares arrays by reference
 * in dependency arrays, passing a new array on each render will cause the
 * IntersectionObserver to be recreated unnecessarily.
 *
 * @example
 * ```tsx
 * // ✅ Correct: Memoize the sectionIds array
 * const sectionIds = useMemo(() => ['section-1', 'section-2', 'section-3'], []);
 * const activeId = useScrollSpy(sectionIds);
 *
 * // ❌ Avoid: Creating new array on each render
 * const activeId = useScrollSpy(['section-1', 'section-2', 'section-3']);
 * ```
 *
 * @example
 * ```tsx
 * const sectionIds = useMemo(() => ['intro', 'features', 'faq'], []);
 * const activeId = useScrollSpy(sectionIds);
 *
 * return (
 *   <nav>
 *     {sectionIds.map(id => (
 *       <a href={`#${id}`} className={activeId === id ? 'active' : ''}>
 *         {id}
 *       </a>
 *     ))}
 *   </nav>
 * );
 * ```
 */
export function useScrollSpy(sectionIds: string[], rootMargin = '-80px 0px -80% 0px'): string | null {
    // Initialize with first section to prevent UI flicker
    const [activeId, setActiveId] = useState<string | null>(sectionIds.length > 0 ? sectionIds[0] : null);

    useEffect(() => {
        // Early return if no sections to observe
        if (sectionIds.length === 0) {
            setActiveId(null);
            return;
        }

        // Track which sections are currently intersecting
        const intersectingIds = new Map<string, boolean>();

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    intersectingIds.set(entry.target.id, entry.isIntersecting);
                });

                // Find the first intersecting section (in DOM order)
                for (const id of sectionIds) {
                    if (intersectingIds.get(id)) {
                        setActiveId(id);
                        return;
                    }
                }

                // If no sections are intersecting, keep current active or use first section
                // This handles edge cases like scrolling before first section or after last
                setActiveId((current) => {
                    // If current is still in the list, keep it
                    if (current && sectionIds.includes(current)) {
                        return current;
                    }
                    // Otherwise default to first section
                    return sectionIds[0];
                });
            },
            {
                rootMargin,
                threshold: 0,
            },
        );

        // Observe all sections
        const elements: Element[] = [];
        sectionIds.forEach((id) => {
            const element = document.getElementById(id);
            if (element) {
                observer.observe(element);
                elements.push(element);
            }
        });

        return () => {
            observer.disconnect();
        };
    }, [sectionIds, rootMargin]);

    return activeId;
}
