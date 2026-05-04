import { describe, expect, it, vi } from 'vitest';

import { mapGetOrInsert, mapGetOrInsertComputed } from '@/lib/map-utils';

describe('mapGetOrInsert', () => {
    it('returns an existing value without overwriting it', () => {
        const map = new Map([['existing', 1]]);

        expect(mapGetOrInsert(map, 'existing', 2)).toBe(1);
        expect(map.get('existing')).toBe(1);
    });

    it('inserts a new value when the key is missing', () => {
        const map = new Map<string, number>();

        expect(mapGetOrInsert(map, 'missing', 2)).toBe(2);
        expect(map.get('missing')).toBe(2);
    });
});

describe('mapGetOrInsertComputed', () => {
    it('computes and stores a missing value once', () => {
        const map = new Map<string, number>();
        const factory = vi.fn((key: string) => key.length);

        expect(mapGetOrInsertComputed(map, 'alpha', factory)).toBe(5);
        expect(mapGetOrInsertComputed(map, 'alpha', factory)).toBe(5);
        expect(factory).toHaveBeenCalledTimes(1);
    });

    it('uses a native getOrInsertComputed implementation when present', () => {
        const map = new Map<string, number>() as Map<string, number> & {
            getOrInsertComputed?: (key: string, factory: (key: string) => number) => number;
        };
        const nativeMethod = vi.fn((key: string) => key.length * 2);
        map.getOrInsertComputed = nativeMethod;

        expect(mapGetOrInsertComputed(map, 'beta', (key) => key.length)).toBe(8);
        expect(nativeMethod).toHaveBeenCalledTimes(1);
    });
});