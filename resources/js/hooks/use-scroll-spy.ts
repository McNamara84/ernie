import { useEffect, useState } from 'react';

/**
 * Hook for scroll-spy functionality.
 * Tracks which section is currently visible in the viewport.
 *
 * @param sectionIds - Array of section IDs to observe
 * @param rootMargin - Margin around the root element (default: '-80px 0px -80% 0px')
 * @returns The ID of the currently active section, or null if none
 *
 * @example
 * ```tsx
 * const activeId = useScrollSpy(['section-1', 'section-2', 'section-3']);
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
    const [activeId, setActiveId] = useState<string | null>(null);

    useEffect(() => {
        // Early return if no sections to observe
        if (sectionIds.length === 0) {
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

        // Set initial active section (first one if none are intersecting)
        if (elements.length > 0) {
            // Only set initial value, let IntersectionObserver handle updates
            setActiveId((current) => current ?? sectionIds[0]);
        }

        return () => {
            observer.disconnect();
        };
    }, [sectionIds, rootMargin]);

    return activeId;
}
