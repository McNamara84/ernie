import { describe, expect, it } from 'vitest';

import {
    FADE_DURATION,
    fadeTransition,
    fadeVariants,
    getReducedMotionProps,
} from '@/lib/animations';

describe('animations', () => {
    describe('constants', () => {
        it('has a FADE_DURATION of 0.2 seconds', () => {
            expect(FADE_DURATION).toBe(0.2);
        });

        it('has correct fadeVariants', () => {
            expect(fadeVariants.initial).toEqual({ opacity: 0 });
            expect(fadeVariants.animate).toEqual({ opacity: 1 });
            expect(fadeVariants.exit).toEqual({ opacity: 0 });
        });

        it('has correct fadeTransition', () => {
            expect(fadeTransition).toEqual({
                duration: 0.2,
                ease: 'easeInOut',
            });
        });
    });

    describe('getReducedMotionProps', () => {
        it('returns animation props when reduced motion is not preferred', () => {
            const props = getReducedMotionProps(false);
            expect(props.initial).toEqual({ opacity: 0 });
            expect(props.animate).toEqual({ opacity: 1 });
            expect(props.exit).toEqual({ opacity: 0 });
            expect(props.transition).toEqual(fadeTransition);
        });

        it('returns no-animation props when reduced motion is preferred', () => {
            const props = getReducedMotionProps(true);
            expect(props.initial).toBe(false);
            expect(props.animate).toEqual({ opacity: 1 });
            expect(props.exit).toEqual({ opacity: 1 });
            expect(props.transition).toEqual({ duration: 0 });
        });
    });
});
