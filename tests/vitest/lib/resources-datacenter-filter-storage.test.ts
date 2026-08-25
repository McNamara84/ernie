/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    clearStoredResourceDatacenterFilter,
    persistResourceDatacenterFilter,
    readStoredResourceDatacenterFilter,
    RESOURCE_DATACENTER_FILTER_STORAGE_KEY,
    storedResourceDatacenterFilterToState,
} from '@/lib/resources-datacenter-filter-storage';

describe('resource datacenter filter storage', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('round-trips a concrete datacenter selection without persisting other filters', () => {
        persistResourceDatacenterFilter({ datacenter_id: 17, search: 'ignored', status: ['published'] });

        expect(readStoredResourceDatacenterFilter()).toEqual({ version: 1, type: 'datacenter', datacenterId: 17 });
        expect(localStorage.getItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY)).not.toContain('ignored');
    });

    it('round-trips the resources-without-datacenter selection', () => {
        persistResourceDatacenterFilter({ without_datacenter: true });
        const storedFilter = readStoredResourceDatacenterFilter();

        expect(storedFilter).toEqual({ version: 1, type: 'without_datacenter' });
        expect(storedFilter && storedResourceDatacenterFilterToState(storedFilter)).toEqual({ without_datacenter: true });
    });

    it('clears the preference when the datacenter filter is removed', () => {
        persistResourceDatacenterFilter({ datacenter_id: 17 });
        persistResourceDatacenterFilter({ search: 'another filter remains' });

        expect(readStoredResourceDatacenterFilter()).toBeNull();
    });

    it.each([
        'not json',
        JSON.stringify({ version: 2, type: 'datacenter', datacenterId: 17 }),
        JSON.stringify({ version: 1, type: 'datacenter', datacenterId: -1 }),
    ])('rejects and removes invalid stored data: %s', (storedValue) => {
        localStorage.setItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY, storedValue);

        expect(readStoredResourceDatacenterFilter()).toBeNull();
        expect(localStorage.getItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY)).toBeNull();
    });

    it('does not leak storage errors into the resource page', () => {
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementationOnce(() => {
            throw new DOMException('Blocked');
        });

        expect(readStoredResourceDatacenterFilter()).toBeNull();
        expect(() => persistResourceDatacenterFilter({ datacenter_id: 17 })).not.toThrow();
        expect(() => clearStoredResourceDatacenterFilter()).not.toThrow();

        getItem.mockRestore();
    });
});
