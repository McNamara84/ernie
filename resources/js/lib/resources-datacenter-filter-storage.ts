import type { ResourceFilterState } from '@/types/resources';

export const RESOURCE_DATACENTER_FILTER_STORAGE_KEY = 'ernie.resources.datacenter-filter.v1';

export type StoredResourceDatacenterFilter = { version: 1; type: 'datacenter'; datacenterId: number } | { version: 1; type: 'without_datacenter' };

function isStoredResourceDatacenterFilter(value: unknown): value is StoredResourceDatacenterFilter {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const candidate = value as Partial<StoredResourceDatacenterFilter> & { datacenterId?: unknown };
    if (candidate.version !== 1) {
        return false;
    }

    if (candidate.type === 'without_datacenter') {
        return true;
    }

    return (
        candidate.type === 'datacenter' &&
        typeof candidate.datacenterId === 'number' &&
        Number.isInteger(candidate.datacenterId) &&
        candidate.datacenterId > 0
    );
}

function getLocalStorage(): Storage | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

export function readStoredResourceDatacenterFilter(): StoredResourceDatacenterFilter | null {
    const storage = getLocalStorage();
    if (!storage) {
        return null;
    }

    try {
        const storedValue = storage.getItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY);
        if (storedValue === null) {
            return null;
        }

        const parsedValue: unknown = JSON.parse(storedValue);
        if (isStoredResourceDatacenterFilter(parsedValue)) {
            return parsedValue;
        }

        storage.removeItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY);
    } catch {
        try {
            storage.removeItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY);
        } catch {
            // Storage may be unavailable (for example in privacy-restricted browsers).
        }
    }

    return null;
}

export function persistResourceDatacenterFilter(filters: ResourceFilterState): void {
    const storage = getLocalStorage();
    if (!storage) {
        return;
    }

    try {
        if (filters.without_datacenter === true) {
            const value: StoredResourceDatacenterFilter = { version: 1, type: 'without_datacenter' };
            storage.setItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY, JSON.stringify(value));

            return;
        }

        if (typeof filters.datacenter_id === 'number' && Number.isInteger(filters.datacenter_id) && filters.datacenter_id > 0) {
            const value: StoredResourceDatacenterFilter = {
                version: 1,
                type: 'datacenter',
                datacenterId: filters.datacenter_id,
            };
            storage.setItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY, JSON.stringify(value));

            return;
        }

        storage.removeItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY);
    } catch {
        // A storage failure must never prevent resource filtering.
    }
}

export function clearStoredResourceDatacenterFilter(): void {
    const storage = getLocalStorage();
    if (!storage) {
        return;
    }

    try {
        storage.removeItem(RESOURCE_DATACENTER_FILTER_STORAGE_KEY);
    } catch {
        // A storage failure must never prevent rendering the resource list.
    }
}

export function storedResourceDatacenterFilterToState(filter: StoredResourceDatacenterFilter): ResourceFilterState {
    return filter.type === 'without_datacenter' ? { without_datacenter: true } : { datacenter_id: filter.datacenterId };
}
