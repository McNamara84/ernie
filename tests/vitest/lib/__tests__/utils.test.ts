import { describe, expect, it } from 'vitest';

import { cn } from '@/lib/utils';

describe('cn', () => {
    it('merges tailwind classes', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });

    it('filters out falsy values', () => {
        expect(cn('p-2', false, undefined, 'text-sm')).toBe('p-2 text-sm');
    });
});
