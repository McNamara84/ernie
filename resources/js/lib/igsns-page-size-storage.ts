export const IGSNS_PAGE_SIZE_STORAGE_KEY = 'ernie.igsns.page-size.v1';
export const IGSNS_PAGE_SIZE_OPTIONS = [10, 100, 1000] as const;

export type IgsnsPageSize = (typeof IGSNS_PAGE_SIZE_OPTIONS)[number];

export function isIgsnsPageSize(value: unknown): value is IgsnsPageSize {
    return typeof value === 'number' && IGSNS_PAGE_SIZE_OPTIONS.some((option) => option === value);
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

export function readStoredIgsnsPageSize(): IgsnsPageSize | null {
    const storage = getLocalStorage();
    if (!storage) {
        return null;
    }

    try {
        const storedValue = storage.getItem(IGSNS_PAGE_SIZE_STORAGE_KEY);
        if (storedValue === null) {
            return null;
        }

        const parsedValue: unknown = JSON.parse(storedValue);
        if (isIgsnsPageSize(parsedValue)) {
            return parsedValue;
        }

        storage.removeItem(IGSNS_PAGE_SIZE_STORAGE_KEY);
    } catch {
        try {
            storage.removeItem(IGSNS_PAGE_SIZE_STORAGE_KEY);
        } catch {
            // Storage may be unavailable in privacy-restricted browsers.
        }
    }

    return null;
}

export function persistIgsnsPageSize(pageSize: IgsnsPageSize): void {
    const storage = getLocalStorage();
    if (!storage) {
        return;
    }

    try {
        storage.setItem(IGSNS_PAGE_SIZE_STORAGE_KEY, JSON.stringify(pageSize));
    } catch {
        // A storage failure must never prevent pagination.
    }
}
