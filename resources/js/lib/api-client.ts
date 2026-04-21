import { buildCsrfHeaders } from '@/lib/csrf-token';

/**
 * Error thrown by {@link apiRequest} when the server returns a non-2xx status.
 *
 * The response body is attached when it can be parsed as JSON, so callers may
 * inspect structured error payloads (e.g. Laravel's `{ message, errors }` shape).
 */
export class ApiError extends Error {
    readonly status: number;
    readonly body: unknown;

    constructor(message: string, status: number, body: unknown = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.body = body;
    }
}

type RequestBody = BodyInit | Record<string, unknown> | unknown[] | null | undefined;

export interface ApiRequestInit extends Omit<RequestInit, 'body'> {
    /**
     * Request body. Plain objects/arrays are automatically JSON-serialised and
     * the `Content-Type: application/json` header is set accordingly.
     */
    body?: RequestBody;

    /**
     * When `true`, suppresses the automatic `X-Requested-With` header and CSRF
     * token injection.
     *
     * Required for cross-origin requests (e.g. fetching external vocabularies)
     * because these non-simple headers would otherwise trigger a CORS preflight
     * that most third-party hosts do not permit.
     *
     * Defaults to `false`; set to `true` only when calling endpoints outside
     * the application's own origin.
     */
    skipCsrf?: boolean;
}

/**
 * Determine whether a target URL is same-origin as the current document.
 *
 * - Relative URLs are always considered same-origin.
 * - When `window.location` is unavailable (e.g. SSR), defaults to `true` to
 *   avoid breaking server-rendered requests that go through `apiRequest`.
 */
const isSameOrigin = (input: string): boolean => {
    if (typeof window === 'undefined' || !window.location) {
        return true;
    }
    try {
        const target = new URL(input, window.location.href);
        return target.origin === window.location.origin;
    } catch {
        return true;
    }
};

const isPlainJsonPayload = (body: RequestBody): body is Record<string, unknown> | unknown[] => {
    if (body === null || body === undefined) {
        return false;
    }
    if (typeof body !== 'object') {
        return false;
    }
    if (body instanceof FormData || body instanceof Blob || body instanceof ArrayBuffer || body instanceof URLSearchParams) {
        return false;
    }
    if (typeof ReadableStream !== 'undefined' && body instanceof ReadableStream) {
        return false;
    }
    return true;
};

/**
 * Thin typed wrapper around `fetch` used by every TanStack Query `queryFn`.
 *
 * Behaviour:
 * - Adds `Accept: application/json`.
 * - For same-origin requests, also adds `X-Requested-With: XMLHttpRequest`
 *   and the current CSRF token (mirroring the axios interceptor used
 *   elsewhere in the app).
 * - For cross-origin requests, CSRF / `X-Requested-With` are **not** injected
 *   by default to avoid triggering a CORS preflight against third-party
 *   hosts. Callers can force this behaviour per request via the `skipCsrf`
 *   option.
 * - Serialises plain objects/arrays as JSON and sets `Content-Type` accordingly.
 * - Throws an {@link ApiError} for non-2xx responses.
 * - Returns `null` for `204 No Content` responses.
 */
export async function apiRequest<T = unknown>(input: string, init: ApiRequestInit = {}): Promise<T> {
    const { body, headers: extraHeaders, skipCsrf = false, ...rest } = init;

    const headers = new Headers(extraHeaders);
    if (!headers.has('Accept')) {
        headers.set('Accept', 'application/json');
    }

    // Only attach non-simple CSRF headers on same-origin requests (and when
    // the caller hasn't explicitly opted out). Cross-origin requests would
    // otherwise trigger a CORS preflight which most third-party hosts deny.
    const attachCsrf = !skipCsrf && isSameOrigin(input);
    if (attachCsrf) {
        if (!headers.has('X-Requested-With')) {
            headers.set('X-Requested-With', 'XMLHttpRequest');
        }
        const csrfHeaders = buildCsrfHeaders();
        for (const [key, value] of Object.entries(csrfHeaders)) {
            if (typeof value === 'string' && !headers.has(key)) {
                headers.set(key, value);
            }
        }
    }

    let finalBody: BodyInit | null | undefined;
    if (isPlainJsonPayload(body)) {
        if (!headers.has('Content-Type')) {
            headers.set('Content-Type', 'application/json');
        }
        finalBody = JSON.stringify(body);
    } else {
        finalBody = (body ?? undefined) as BodyInit | undefined;
    }

    const response = await fetch(input, {
        ...rest,
        headers,
        body: finalBody,
    });

    if (response.status === 204) {
        return null as T;
    }

    if (!response.ok) {
        const parsed = await response.clone().json().catch(() => null);
        const message = typeof (parsed as { message?: unknown })?.message === 'string'
            ? (parsed as { message: string }).message
            : `Request failed with status ${response.status}`;
        throw new ApiError(message, response.status, parsed);
    }

    // Allow empty response bodies on 2xx to not blow up.
    const text = await response.text();
    if (!text) {
        return null as T;
    }

    try {
        return JSON.parse(text) as T;
    } catch (error) {
        throw new ApiError('Failed to parse JSON response', response.status, { raw: text, cause: String(error) });
    }
}
