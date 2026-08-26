/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    clearStoredIgsnDatacenterFilter,
    IGSNS_DATACENTER_FILTER_STORAGE_KEY,
    persistIgsnDatacenterFilter,
    readStoredIgsnDatacenterFilter,
    storedIgsnDatacenterFilterToState,
} from '@/lib/igsns-datacenter-filter-storage';

describe('IGSN datacenter filter storage', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('round-trips a concrete datacenter without persisting unrelated filters', () => {
        persistIgsnDatacenterFilter({ datacenter_id: 17 });

        expect(readStoredIgsnDatacenterFilter()).toEqual({ version: 1, type: 'datacenter', datacenterId: 17 });
        expect(storedIgsnDatacenterFilterToState(readStoredIgsnDatacenterFilter()!)).toEqual({ datacenter_id: 17 });
        expect(localStorage.getItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY)).not.toContain('search');
    });

    it('round-trips the IGSNs-without-datacenter selection', () => {
        persistIgsnDatacenterFilter({ without_datacenter: true });
        const storedFilter = readStoredIgsnDatacenterFilter();

        expect(storedFilter).toEqual({ version: 1, type: 'without_datacenter' });
        expect(storedFilter && storedIgsnDatacenterFilterToState(storedFilter)).toEqual({ without_datacenter: true });
    });

    it('prefers the unassigned selection when defensive input contains both choices', () => {
        persistIgsnDatacenterFilter({ datacenter_id: 17, without_datacenter: true });

        expect(readStoredIgsnDatacenterFilter()).toEqual({ version: 1, type: 'without_datacenter' });
    });

    it('clears the preference when the datacenter filter is removed', () => {
        persistIgsnDatacenterFilter({ datacenter_id: 17 });
        persistIgsnDatacenterFilter({});

        expect(readStoredIgsnDatacenterFilter()).toBeNull();
    });

    it.each([
        'not json',
        JSON.stringify({ version: 2, type: 'datacenter', datacenterId: 17 }),
        JSON.stringify({ version: 1, type: 'datacenter', datacenterId: -1 }),
        JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 2.5 }),
        JSON.stringify({ version: 1, type: 'datacenter', datacenterId: '17' }),
        JSON.stringify({ version: 1, type: 'unknown' }),
    ])('rejects and removes invalid stored data: %s', (storedValue) => {
        localStorage.setItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY, storedValue);

        expect(readStoredIgsnDatacenterFilter()).toBeNull();
        expect(localStorage.getItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY)).toBeNull();
    });

    it('does not leak storage errors into the IGSN page', () => {
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementationOnce(() => {
            throw new DOMException('Blocked');
        });

        expect(readStoredIgsnDatacenterFilter()).toBeNull();
        expect(() => persistIgsnDatacenterFilter({ datacenter_id: 17 })).not.toThrow();
        expect(() => clearStoredIgsnDatacenterFilter()).not.toThrow();

        getItem.mockRestore();
    });
});
