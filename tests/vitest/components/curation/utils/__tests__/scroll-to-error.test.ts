import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { scheduleScrollToError } from '@/components/curation/utils/scroll-to-error';

describe('scheduleScrollToError', () => {
    let rAFCallback: FrameRequestCallback;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb) => {
            rAFCallback = cb;
            return 1;
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /** Flush the deferred rAF + setTimeout pipeline. */
    function flush(delay = 200): void {
        // Execute the rAF callback
        rAFCallback(performance.now());
        // Advance the inner setTimeout
        vi.advanceTimersByTime(delay);
    }

    it('scrolls to and focuses the field matched by fieldSelector', () => {
        const field = document.createElement('input');
        field.setAttribute('data-testid', 'my-field');
        document.body.appendChild(field);

        const scrollSpy = vi.spyOn(field, 'scrollIntoView');
        const focusSpy = vi.spyOn(field, 'focus');

        scheduleScrollToError('[data-testid="my-field"]', 'some-section');
        flush();

        expect(scrollSpy).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
        expect(focusSpy).toHaveBeenCalledWith({ preventScroll: true });
    });

    it('falls back to accordion trigger when fieldSelector is null', () => {
        const item = document.createElement('div');
        item.setAttribute('data-accordion-value', 'authors');
        const trigger = document.createElement('button');
        trigger.setAttribute('data-slot', 'accordion-trigger');
        item.appendChild(trigger);
        document.body.appendChild(item);

        const scrollSpy = vi.spyOn(trigger, 'scrollIntoView');
        const focusSpy = vi.spyOn(trigger, 'focus');

        scheduleScrollToError(null, 'authors');
        flush();

        expect(scrollSpy).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
        expect(focusSpy).toHaveBeenCalledWith({ preventScroll: true });
    });

    it('falls back to accordion item when trigger is missing', () => {
        const item = document.createElement('div');
        item.setAttribute('data-accordion-value', 'descriptions');
        document.body.appendChild(item);

        const scrollSpy = vi.spyOn(item, 'scrollIntoView');

        scheduleScrollToError(undefined, 'descriptions');
        flush();

        expect(scrollSpy).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
    });

    it('falls back to accordion trigger when fieldSelector does not match', () => {
        const item = document.createElement('div');
        item.setAttribute('data-accordion-value', 'authors');
        const trigger = document.createElement('button');
        trigger.setAttribute('data-slot', 'accordion-trigger');
        item.appendChild(trigger);
        document.body.appendChild(item);

        const scrollSpy = vi.spyOn(trigger, 'scrollIntoView');

        scheduleScrollToError('[data-testid="nonexistent"]', 'authors');
        flush();

        expect(scrollSpy).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
    });

    it('does nothing when no matching element exists', () => {
        // Should not throw
        scheduleScrollToError('[data-testid="ghost"]', 'ghost-section');
        flush();
    });

    it('uses custom delay when provided', () => {
        const field = document.createElement('input');
        field.setAttribute('data-testid', 'delayed');
        document.body.appendChild(field);

        const scrollSpy = vi.spyOn(field, 'scrollIntoView');

        scheduleScrollToError('[data-testid="delayed"]', 'section', 500);

        // Execute rAF
        rAFCallback(performance.now());

        // Not yet — only 200 ms
        vi.advanceTimersByTime(200);
        expect(scrollSpy).not.toHaveBeenCalled();

        // Now at 500 ms
        vi.advanceTimersByTime(300);
        expect(scrollSpy).toHaveBeenCalled();
    });
});
