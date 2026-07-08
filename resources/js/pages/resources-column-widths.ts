export type ResourceColumnKey = 'id_resourcetype' | 'doi_title' | 'author_year' | 'curator_status' | 'created_updated';

interface ResourceColumnWidthDefinition {
    defaultWidth: number;
    minWidth: number;
    maxWidth: number;
}

export type ResourceColumnWidths = Record<ResourceColumnKey, number>;

export const RESIZABLE_TABLE_MIN_VIEWPORT_WIDTH = 768;
export const COLUMN_RESIZE_STEP = 16;
export const COLUMN_RESIZE_LARGE_STEP = 48;
export const COLUMN_WIDTH_STORAGE_KEY = 'resources.column-widths';
export const RESOURCE_COLUMN_WIDTH_DEFINITIONS: Record<ResourceColumnKey, ResourceColumnWidthDefinition> = {
    id_resourcetype: { defaultWidth: 160, minWidth: 120, maxWidth: 280 },
    doi_title: { defaultWidth: 384, minWidth: 220, maxWidth: 720 },
    author_year: { defaultWidth: 208, minWidth: 140, maxWidth: 360 },
    curator_status: { defaultWidth: 176, minWidth: 140, maxWidth: 320 },
    created_updated: { defaultWidth: 160, minWidth: 128, maxWidth: 260 },
};
export const RESOURCE_COLUMN_RESIZE_LABELS: Record<ResourceColumnKey, string> = {
    id_resourcetype: 'ID and Resource Type',
    doi_title: 'DOI and Title',
    author_year: 'Author and Year',
    curator_status: 'Curator and Status',
    created_updated: 'Created and Updated dates',
};
export const RESOURCE_COLUMN_KEYS = Object.keys(RESOURCE_COLUMN_WIDTH_DEFINITIONS) as ResourceColumnKey[];

const buildDefaultColumnWidths = (): ResourceColumnWidths =>
    RESOURCE_COLUMN_KEYS.reduce((widths, columnKey) => {
        widths[columnKey] = RESOURCE_COLUMN_WIDTH_DEFINITIONS[columnKey].defaultWidth;
        return widths;
    }, {} as ResourceColumnWidths);

export const DEFAULT_RESOURCE_COLUMN_WIDTHS = buildDefaultColumnWidths();

export const clampColumnWidth = (columnKey: ResourceColumnKey, width: number): number => {
    const definition = RESOURCE_COLUMN_WIDTH_DEFINITIONS[columnKey];

    if (!Number.isFinite(width)) {
        return definition.defaultWidth;
    }

    return Math.min(definition.maxWidth, Math.max(definition.minWidth, Math.round(width)));
};

export const normalizeResourceColumnWidths = (value: unknown): ResourceColumnWidths => {
    const widths = buildDefaultColumnWidths();

    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return widths;
    }

    const storedWidths = value as Partial<Record<ResourceColumnKey, unknown>>;

    RESOURCE_COLUMN_KEYS.forEach((columnKey) => {
        const storedWidth = storedWidths[columnKey];
        if (typeof storedWidth === 'number') {
            widths[columnKey] = clampColumnWidth(columnKey, storedWidth);
        }
    });

    return widths;
};

export const parseStoredResourceColumnWidths = (storedValue: string | null): ResourceColumnWidths | null => {
    if (!storedValue) {
        return null;
    }

    try {
        return normalizeResourceColumnWidths(JSON.parse(storedValue));
    } catch {
        return null;
    }
};

export const readStoredResourceColumnWidths = (): ResourceColumnWidths => {
    if (typeof window === 'undefined') {
        return buildDefaultColumnWidths();
    }

    try {
        return parseStoredResourceColumnWidths(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)) ?? buildDefaultColumnWidths();
    } catch {
        return buildDefaultColumnWidths();
    }
};

export const persistResourceColumnWidths = (widths: ResourceColumnWidths): void => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(COLUMN_WIDTH_STORAGE_KEY, JSON.stringify(normalizeResourceColumnWidths(widths)));
    } catch {
        // Ignore storage failures; resizing should remain usable for the session.
    }
};

export const clearStoredResourceColumnWidths = (): void => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.removeItem(COLUMN_WIDTH_STORAGE_KEY);
    } catch {
        // Ignore storage failures; the in-memory reset still applies.
    }
};

export const areResourceColumnWidthsDefault = (widths: ResourceColumnWidths): boolean =>
    RESOURCE_COLUMN_KEYS.every((columnKey) => widths[columnKey] === DEFAULT_RESOURCE_COLUMN_WIDTHS[columnKey]);

export const isResizableViewport = (): boolean => (typeof window === 'undefined' ? true : window.innerWidth >= RESIZABLE_TABLE_MIN_VIEWPORT_WIDTH);

export const shouldIncludeColumnInLayout = (column: { key: ResourceColumnKey }, tableCanResize: boolean): boolean => {
    if (column.key === 'created_updated' && !tableCanResize) {
        return false;
    }

    return true;
};
