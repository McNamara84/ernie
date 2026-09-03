import type { FeedbackDiagnosticEvent, FeedbackEnvironment, FeedbackTechnicalSnapshot } from '@/types/feedback';

export const FEEDBACK_DIAGNOSTIC_LIMIT = 10;
export const FEEDBACK_DIAGNOSTIC_STORAGE_KEY = 'ernie.feedback.diagnostics.v1';

const STORAGE_VERSION = 1;
const MAX_MESSAGE_LENGTH = 500;
const MAX_PATH_LENGTH = 2048;
const HTTP_METHODS = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']);

interface StoredFeedbackDiagnostics {
    version: typeof STORAGE_VERSION;
    userId: number;
    events: FeedbackDiagnosticEvent[];
}

let memoryState: StoredFeedbackDiagnostics | null = null;

function bounded(value: string, maximum: number): string {
    return value.slice(0, maximum);
}

export function sanitizeFeedbackDiagnosticMessage(value: string): string {
    return bounded(
        value
            // Deliberately strip non-printing control characters from user-visible diagnostics.
            // eslint-disable-next-line no-control-regex
            .replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/gu, '')
            .replace(/(https?:\/\/[^\s?#]+)[?#][^\s]*/giu, '$1')
            .replace(/(^|[^\w:/])(\/[^\s?#]*)[?#][^\s]*/gu, '$1$2')
            .replace(/\bBearer\s+[A-Za-z0-9._~+/=-]+/giu, 'Bearer [redacted-token]')
            .replace(/\b[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\b/gu, '[redacted-token]')
            .replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/giu, '[redacted-email]')
            .replace(/\b[A-F0-9]{32,}\b/giu, '[redacted-token]')
            .trim(),
        MAX_MESSAGE_LENGTH,
    );
}

export function feedbackPathFromUrl(value: string | undefined): string | null {
    if (!value || typeof window === 'undefined') return null;

    try {
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) return null;

        const path = bounded(url.pathname || '/', MAX_PATH_LENGTH);
        return path.startsWith('/') && !path.startsWith('//') ? path : null;
    } catch {
        return null;
    }
}

function hasOnlyKeys(value: Record<string, unknown>, keys: string[]): boolean {
    return Object.keys(value).every((key) => keys.includes(key));
}

function isIsoTimestamp(value: unknown): value is string {
    return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value) && !Number.isNaN(Date.parse(value));
}

function isFeedbackPath(value: unknown): value is string {
    return (
        typeof value === 'string' &&
        value.length > 0 &&
        value.length <= MAX_PATH_LENGTH &&
        value.startsWith('/') &&
        !value.startsWith('//') &&
        !value.includes('?') &&
        !value.includes('#')
    );
}

function isDiagnosticEvent(value: unknown): value is FeedbackDiagnosticEvent {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    const event = value as Record<string, unknown>;
    if (!isIsoTimestamp(event.occurred_at) || typeof event.type !== 'string') return false;

    if (event.type === 'navigation') {
        return hasOnlyKeys(event, ['type', 'occurred_at', 'path']) && isFeedbackPath(event.path);
    }

    if (event.type === 'http_error') {
        return (
            hasOnlyKeys(event, ['type', 'occurred_at', 'method', 'path', 'status', 'message']) &&
            typeof event.method === 'string' &&
            HTTP_METHODS.has(event.method) &&
            isFeedbackPath(event.path) &&
            (event.status === undefined || (Number.isInteger(event.status) && Number(event.status) >= 100 && Number(event.status) <= 599)) &&
            (event.message === undefined || (typeof event.message === 'string' && event.message.length <= MAX_MESSAGE_LENGTH))
        );
    }

    return (
        event.type === 'javascript_error' &&
        hasOnlyKeys(event, ['type', 'occurred_at', 'message']) &&
        typeof event.message === 'string' &&
        event.message.length > 0 &&
        event.message.length <= MAX_MESSAGE_LENGTH
    );
}

function emptyState(userId: number): StoredFeedbackDiagnostics {
    return { version: STORAGE_VERSION, userId, events: [] };
}

function readStoredState(userId: number): StoredFeedbackDiagnostics {
    try {
        const raw = window.sessionStorage.getItem(FEEDBACK_DIAGNOSTIC_STORAGE_KEY);
        if (raw !== null) {
            const parsed = JSON.parse(raw) as unknown;
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                const state = parsed as Record<string, unknown>;
                if (
                    state.version === STORAGE_VERSION &&
                    state.userId === userId &&
                    Array.isArray(state.events) &&
                    state.events.length <= FEEDBACK_DIAGNOSTIC_LIMIT &&
                    state.events.every(isDiagnosticEvent)
                ) {
                    memoryState = state as unknown as StoredFeedbackDiagnostics;
                    return memoryState;
                }
            }
        }
    } catch {
        // sessionStorage can be unavailable or contain invalid JSON. The in-memory
        // fallback intentionally keeps feedback available without leaking old data.
    }

    if (memoryState?.userId === userId) return memoryState;

    memoryState = emptyState(userId);
    return memoryState;
}

function persistState(state: StoredFeedbackDiagnostics): void {
    memoryState = state;

    try {
        window.sessionStorage.setItem(FEEDBACK_DIAGNOSTIC_STORAGE_KEY, JSON.stringify(state));
    } catch {
        // The bounded in-memory state remains usable when browser storage is blocked.
    }
}

export function initializeFeedbackDiagnostics(userId: number): void {
    const state = readStoredState(userId);
    if (state.userId !== userId) {
        persistState(emptyState(userId));
        return;
    }

    persistState(state);
}

export function recordFeedbackDiagnostic(userId: number, event: FeedbackDiagnosticEvent): void {
    if (!isDiagnosticEvent(event)) return;

    const state = readStoredState(userId);
    const lastEvent = state.events.at(-1);
    if (event.type === 'navigation' && lastEvent?.type === 'navigation' && lastEvent.path === event.path) return;

    const events = [...state.events, event].slice(-FEEDBACK_DIAGNOSTIC_LIMIT);
    persistState({ version: STORAGE_VERSION, userId, events });
}

export function getFeedbackDiagnostics(userId: number): FeedbackDiagnosticEvent[] {
    return readStoredState(userId).events.map((event) => ({ ...event }));
}

function readAppearance(): FeedbackEnvironment['appearance'] {
    try {
        const appearance = window.localStorage.getItem('appearance');
        if (appearance === 'light' || appearance === 'dark' || appearance === 'system') return appearance;
    } catch {
        // The resolved theme still provides useful context when localStorage is blocked.
    }

    return 'system';
}

export function createFeedbackTechnicalSnapshot(userId: number): FeedbackTechnicalSnapshot {
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const locale = navigator.language || 'en';

    return {
        page: {
            path: feedbackPathFromUrl(window.location.href) ?? '/',
            title: bounded(document.title || 'ERNIE', 255),
        },
        environment: {
            appearance: readAppearance(),
            resolved_theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
            viewport_width: Math.max(1, Math.min(10000, Math.round(window.innerWidth))),
            viewport_height: Math.max(1, Math.min(10000, Math.round(window.innerHeight))),
            device_pixel_ratio: Math.max(0.1, Math.min(10, window.devicePixelRatio || 1)),
            locale: bounded(locale.replace(/[^A-Za-z0-9_-]/g, '') || 'en', 35),
            timezone: bounded(timezone.replace(/[^A-Za-z0-9_+/-]/g, '') || 'UTC', 64),
        },
        diagnostics: getFeedbackDiagnostics(userId),
    };
}

export function resetFeedbackDiagnosticsForTests(): void {
    memoryState = null;
    try {
        window.sessionStorage.removeItem(FEEDBACK_DIAGNOSTIC_STORAGE_KEY);
    } catch {
        // Test and privacy-restricted environments may not expose sessionStorage.
    }
}
