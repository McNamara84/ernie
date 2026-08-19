export const EDITOR_LOAD_TOKEN_HEADER = 'X-Editor-Load-Token';
export const EDITOR_SERVER_PROGRESS_MAX = 75;
export const EDITOR_RESOURCE_TYPES_PROGRESS = 78;
export const EDITOR_CLIENT_READY_PROGRESS = 100;

export const EDITOR_LOADING_MESSAGES = [
    'Preparing the Data Editor for the Data Curators work',
    'Load user-specific settings for Data Editor',
    'Ask ELMO if Cookie Monster still has any cookies',
    'Load unicorns into the DataCite cache',
    'Groan under the weight of the huge dataset',
    'Who on earth works with such massive datasets?',
] as const;

export const editorLoadStatusUrl = (token: string): string => `/editor/resource-loads/${encodeURIComponent(token)}/status`;
export const editorLoadSlowUrl = (token: string): string => `/editor/resource-loads/${encodeURIComponent(token)}/slow`;

const timelineStorageKey = (token: string): string => `editor-load:${token}:started-at`;
const slowReportedStorageKey = (token: string): string => `editor-load:${token}:slow-reported`;
const inMemoryStartedAt = new Map<string, number>();
const inMemorySlowReports = new Set<string>();

export function getEditorLoadStartedAt(token: string): number {
    if (typeof window === 'undefined') {
        return Date.now();
    }

    try {
        const stored = Number(window.sessionStorage.getItem(timelineStorageKey(token)));
        if (Number.isFinite(stored) && stored > 0) {
            inMemoryStartedAt.set(token, stored);
            return stored;
        }
    } catch {
        // Module memory still preserves the timeline across Inertia components.
    }

    const inMemoryValue = inMemoryStartedAt.get(token);
    if (inMemoryValue !== undefined) return inMemoryValue;

    const startedAt = Date.now();
    inMemoryStartedAt.set(token, startedAt);
    try {
        window.sessionStorage.setItem(timelineStorageKey(token), String(startedAt));
    } catch {
        // Storage can be unavailable in privacy-restricted browser contexts.
    }

    return startedAt;
}

export function getEditorLoadElapsedMs(token: string): number {
    return Math.max(0, Date.now() - getEditorLoadStartedAt(token));
}

export function hasReportedSlowEditorLoad(token: string): boolean {
    if (inMemorySlowReports.has(token)) return true;
    if (typeof window === 'undefined') return false;

    try {
        return window.sessionStorage.getItem(slowReportedStorageKey(token)) === 'true';
    } catch {
        return false;
    }
}

export function markSlowEditorLoadReported(token: string): void {
    inMemorySlowReports.add(token);
    if (typeof window !== 'undefined') {
        try {
            window.sessionStorage.setItem(slowReportedStorageKey(token), 'true');
        } catch {
            // The in-memory marker still prevents duplicate requests in this tab.
        }
    }
}

export function clearEditorLoadTimeline(token: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    inMemoryStartedAt.delete(token);
    inMemorySlowReports.delete(token);
    try {
        window.sessionStorage.removeItem(timelineStorageKey(token));
        window.sessionStorage.removeItem(slowReportedStorageKey(token));
    } catch {
        // Nothing else needs cleanup when storage is unavailable.
    }
}
