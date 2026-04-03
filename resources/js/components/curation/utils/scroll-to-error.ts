/**
 * Default delay (ms) after an accordion section opens before scrolling/focusing.
 * This should match the Radix Accordion CSS transition duration defined in the
 * AccordionContent component (currently ~200 ms).  If the accordion animation
 * timing is changed, update this constant accordingly.
 */
const ACCORDION_ANIMATION_DELAY_MS = 200;

/**
 * Schedule a scroll-and-focus to the first matching error target.
 *
 * Resolution order:
 *  1. `fieldSelector` — a CSS selector pointing directly at the invalid field.
 *  2. Accordion section trigger — the focusable `[data-slot="accordion-trigger"]`
 *     button inside the `[data-accordion-value]` item for the given `sectionId`.
 *  3. The accordion item element itself (last resort).
 *
 * The scroll is deferred via `requestAnimationFrame` + a configurable timeout so
 * the accordion has time to finish its open animation and the target element is
 * rendered in the DOM.
 */
export function scheduleScrollToError(
    fieldSelector: string | null | undefined,
    sectionId: string,
    delay: number = ACCORDION_ANIMATION_DELAY_MS,
): void {
    requestAnimationFrame(() => {
        setTimeout(() => {
            let target: Element | null = null;

            if (fieldSelector) {
                target = document.querySelector(fieldSelector);
            }
            if (!target) {
                const accordionItem = document.querySelector(`[data-accordion-value="${sectionId}"]`);
                target = accordionItem?.querySelector('[data-slot="accordion-trigger"]') ?? accordionItem;
            }

            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (target instanceof HTMLElement) {
                    target.focus({ preventScroll: true });
                }
            }
        }, delay);
    });
}
