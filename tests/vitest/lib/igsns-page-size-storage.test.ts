/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { IGSNS_PAGE_SIZE_STORAGE_KEY, persistIgsnsPageSize, readStoredIgsnsPageSize } from '@/lib/igsns-page-size-storage';

describe('IGSN page-size storage', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it.each([10, 100, 1000] as const)('round-trips the supported page size %i', (pageSize) => {
        persistIgsnsPageSize(pageSize);

        expect(readStoredIgsnsPageSize()).toBe(pageSize);
        expect(localStorage.getItem(IGSNS_PAGE_SIZE_STORAGE_KEY)).toBe(String(pageSize));
    });

    it.each(['not json', '25', '0', '"100"', 'null'])('rejects and removes an invalid stored value: %s', (storedValue) => {
        localStorage.setItem(IGSNS_PAGE_SIZE_STORAGE_KEY, storedValue);

        expect(readStoredIgsnsPageSize()).toBeNull();
        expect(localStorage.getItem(IGSNS_PAGE_SIZE_STORAGE_KEY)).toBeNull();
    });

    it('does not leak storage errors into the IGSN page', () => {
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementationOnce(() => {
            throw new DOMException('Blocked');
        });

        expect(readStoredIgsnsPageSize()).toBeNull();
        expect(() => persistIgsnsPageSize(1000)).not.toThrow();

        getItem.mockRestore();
    });
});
