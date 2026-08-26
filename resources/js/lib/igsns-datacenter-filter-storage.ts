export const IGSNS_DATACENTER_FILTER_STORAGE_KEY = 'ernie.igsns.datacenter-filter.v1';

export interface IgsnDatacenterFilterState {
    datacenter_id?: number;
    without_datacenter?: boolean;
}

export type StoredIgsnDatacenterFilter = { version: 1; type: 'datacenter'; datacenterId: number } | { version: 1; type: 'without_datacenter' };

function isStoredIgsnDatacenterFilter(value: unknown): value is StoredIgsnDatacenterFilter {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const candidate = value as Partial<StoredIgsnDatacenterFilter> & { datacenterId?: unknown };
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

export function readStoredIgsnDatacenterFilter(): StoredIgsnDatacenterFilter | null {
    const storage = getLocalStorage();
    if (!storage) {
        return null;
    }

    try {
        const storedValue = storage.getItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY);
        if (storedValue === null) {
            return null;
        }

        const parsedValue: unknown = JSON.parse(storedValue);
        if (isStoredIgsnDatacenterFilter(parsedValue)) {
            return parsedValue;
        }

        storage.removeItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY);
    } catch {
        try {
            storage.removeItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY);
        } catch {
            // Storage may be unavailable in privacy-restricted browsers.
        }
    }

    return null;
}

export function persistIgsnDatacenterFilter(filters: IgsnDatacenterFilterState): void {
    const storage = getLocalStorage();
    if (!storage) {
        return;
    }

    try {
        if (filters.without_datacenter === true) {
            const value: StoredIgsnDatacenterFilter = { version: 1, type: 'without_datacenter' };
            storage.setItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY, JSON.stringify(value));

            return;
        }

        if (typeof filters.datacenter_id === 'number' && Number.isInteger(filters.datacenter_id) && filters.datacenter_id > 0) {
            const value: StoredIgsnDatacenterFilter = {
                version: 1,
                type: 'datacenter',
                datacenterId: filters.datacenter_id,
            };
            storage.setItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY, JSON.stringify(value));

            return;
        }

        storage.removeItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY);
    } catch {
        // A storage failure must never prevent IGSN filtering.
    }
}

export function clearStoredIgsnDatacenterFilter(): void {
    const storage = getLocalStorage();
    if (!storage) {
        return;
    }

    try {
        storage.removeItem(IGSNS_DATACENTER_FILTER_STORAGE_KEY);
    } catch {
        // A storage failure must never prevent rendering the IGSN list.
    }
}

export function storedIgsnDatacenterFilterToState(filter: StoredIgsnDatacenterFilter): IgsnDatacenterFilterState {
    return filter.type === 'without_datacenter' ? { without_datacenter: true } : { datacenter_id: filter.datacenterId };
}
