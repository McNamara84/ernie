import { describe, expect, it } from 'vitest';
import { cn } from '../utils';

describe('cn', () => {
    it('combines class names', () => {
        expect(cn('p-2', 'm-2')).toBe('p-2 m-2');
    });

    it('overrides conflicting tailwind classes', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });

    it('handles conditional classes', () => {
        expect(cn('p-2', { hidden: false, block: true })).toBe('p-2 block');
    });
});
