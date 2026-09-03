import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    createFeedbackTechnicalSnapshot,
    FEEDBACK_DIAGNOSTIC_LIMIT,
    FEEDBACK_DIAGNOSTIC_STORAGE_KEY,
    feedbackPathFromUrl,
    getFeedbackDiagnostics,
    initializeFeedbackDiagnostics,
    recordFeedbackDiagnostic,
    resetFeedbackDiagnosticsForTests,
    sanitizeFeedbackDiagnosticMessage,
} from '@/lib/feedback-diagnostics';
import type { FeedbackDiagnosticEvent } from '@/types/feedback';

const occurredAt = (second: number) => `2026-09-02T12:00:${String(second).padStart(2, '0')}.000Z`;

describe('feedback diagnostics', () => {
    beforeEach(() => {
        resetFeedbackDiagnosticsForTests();
        window.history.replaceState({}, '', '/resources?query=private#result');
        document.title = 'Resources — ERNIE';
        document.documentElement.classList.remove('dark');
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        resetFeedbackDiagnosticsForTests();
    });

    it('retains only the last ten events in chronological order', () => {
        initializeFeedbackDiagnostics(7);

        for (let index = 0; index < FEEDBACK_DIAGNOSTIC_LIMIT + 2; index += 1) {
            recordFeedbackDiagnostic(7, {
                type: 'javascript_error',
                occurred_at: occurredAt(index),
                message: `Error ${index}`,
            });
        }

        const diagnostics = getFeedbackDiagnostics(7);
        expect(diagnostics).toHaveLength(10);
        expect(diagnostics[0]).toMatchObject({ message: 'Error 2' });
        expect(diagnostics.at(-1)).toMatchObject({ message: 'Error 11' });
    });

    it('does not duplicate consecutive navigation events from remounts', () => {
        const navigation = { type: 'navigation', occurred_at: occurredAt(1), path: '/resources' } as const;

        recordFeedbackDiagnostic(7, navigation);
        recordFeedbackDiagnostic(7, { ...navigation, occurred_at: occurredAt(2) });

        expect(getFeedbackDiagnostics(7)).toEqual([navigation]);
    });

    it('removes queries, fragments and sensitive token-like content', () => {
        expect(feedbackPathFromUrl('/resources?email=jane@example.org#private')).toBe('/resources');
        expect(feedbackPathFromUrl('https://external.example/resources?token=secret')).toBeNull();
        expect(
            sanitizeFeedbackDiagnosticMessage(
                'GET https://ernie.test/resources?email=jane@example.org Bearer abc.def.ghi jane@example.org aabbccddeeff00112233445566778899',
            ),
        ).toBe('GET https://ernie.test/resources Bearer [redacted-token] [redacted-email] [redacted-token]');
    });

    it('rejects malformed stored events and unknown fields', () => {
        window.sessionStorage.setItem(
            FEEDBACK_DIAGNOSTIC_STORAGE_KEY,
            JSON.stringify({
                version: 1,
                userId: 7,
                events: [{ type: 'navigation', occurred_at: occurredAt(1), path: '/resources', secret: 'value' }],
            }),
        );

        initializeFeedbackDiagnostics(7);
        recordFeedbackDiagnostic(7, { type: 'unknown', occurred_at: occurredAt(2), body: 'private' } as unknown as FeedbackDiagnosticEvent);

        expect(getFeedbackDiagnostics(7)).toEqual([]);
    });

    it('discards diagnostics when the authenticated user changes', () => {
        recordFeedbackDiagnostic(7, { type: 'navigation', occurred_at: occurredAt(1), path: '/resources' });

        initializeFeedbackDiagnostics(8);

        expect(getFeedbackDiagnostics(8)).toEqual([]);
        expect(JSON.parse(window.sessionStorage.getItem(FEEDBACK_DIAGNOSTIC_STORAGE_KEY) ?? '{}')).toMatchObject({ userId: 8, events: [] });
    });

    it('falls back to bounded memory when session storage is blocked', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new DOMException('Blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('Blocked');
        });

        initializeFeedbackDiagnostics(9);
        recordFeedbackDiagnostic(9, { type: 'javascript_error', occurred_at: occurredAt(1), message: 'Safe error' });

        expect(getFeedbackDiagnostics(9)).toEqual([{ type: 'javascript_error', occurred_at: occurredAt(1), message: 'Safe error' }]);
    });

    it('creates a point-in-time snapshot with the visible environment', () => {
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1440 });
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 900 });
        Object.defineProperty(window, 'devicePixelRatio', { configurable: true, value: 2 });
        window.localStorage.setItem('appearance', 'system');
        document.documentElement.classList.add('dark');
        recordFeedbackDiagnostic(7, { type: 'navigation', occurred_at: occurredAt(1), path: '/resources' });

        const snapshot = createFeedbackTechnicalSnapshot(7);
        recordFeedbackDiagnostic(7, { type: 'javascript_error', occurred_at: occurredAt(2), message: 'Later event' });

        expect(snapshot).toMatchObject({
            page: { path: '/resources', title: 'Resources — ERNIE' },
            environment: {
                appearance: 'system',
                resolved_theme: 'dark',
                viewport_width: 1440,
                viewport_height: 900,
                device_pixel_ratio: 2,
            },
        });
        expect(snapshot.diagnostics).toEqual([{ type: 'navigation', occurred_at: occurredAt(1), path: '/resources' }]);
        expect(getFeedbackDiagnostics(7)).toHaveLength(2);
    });
});
