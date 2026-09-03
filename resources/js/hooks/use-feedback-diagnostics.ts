import { router, usePage } from '@inertiajs/react';
import axios, { type AxiosError } from 'axios';
import { useEffect } from 'react';

import {
    feedbackPathFromUrl,
    initializeFeedbackDiagnostics,
    recordFeedbackDiagnostic,
    sanitizeFeedbackDiagnosticMessage,
} from '@/lib/feedback-diagnostics';
import type { SharedData } from '@/types';
import type { FeedbackHttpErrorDiagnostic } from '@/types/feedback';

function javascriptErrorMessage(value: unknown, fallback: string): string {
    if (value instanceof Error) return `${value.name}: ${value.message}`;
    if (typeof value === 'string') return value;
    return fallback;
}

function httpDiagnostic(error: AxiosError): FeedbackHttpErrorDiagnostic | null {
    if (axios.isCancel(error) || error.code === 'ERR_CANCELED') return null;

    const path = feedbackPathFromUrl(error.config?.url);
    if (path === null || /\/feedback\/?$/.test(path)) return null;

    const method = error.config?.method?.toUpperCase() ?? 'GET';
    if (!['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'].includes(method)) return null;

    const status = error.response?.status;
    return {
        type: 'http_error',
        occurred_at: new Date().toISOString(),
        method: method as FeedbackHttpErrorDiagnostic['method'],
        path,
        ...(typeof status === 'number' ? { status } : {}),
        message: sanitizeFeedbackDiagnosticMessage(typeof status === 'number' ? `HTTP request failed (${status})` : 'Network request failed'),
    };
}

export function useFeedbackDiagnostics(): void {
    const userId = usePage<SharedData>().props.auth.user.id;

    useEffect(() => {
        initializeFeedbackDiagnostics(userId);

        const initialPath = feedbackPathFromUrl(window.location.href);
        if (initialPath !== null) {
            recordFeedbackDiagnostic(userId, {
                type: 'navigation',
                occurred_at: new Date().toISOString(),
                path: initialPath,
            });
        }

        const removeFinish = router.on('finish', (event) => {
            if (!event.detail.visit.completed) return;

            const path = feedbackPathFromUrl(window.location.href);
            if (path !== null) {
                recordFeedbackDiagnostic(userId, {
                    type: 'navigation',
                    occurred_at: new Date().toISOString(),
                    path,
                });
            }
        });

        const interceptorId = axios.interceptors.response.use(
            (response) => response,
            (error: unknown) => {
                const diagnostic = axios.isAxiosError(error) ? httpDiagnostic(error) : null;
                if (diagnostic !== null) recordFeedbackDiagnostic(userId, diagnostic);
                return Promise.reject(error);
            },
        );

        const handleError = (event: ErrorEvent) => {
            const message = sanitizeFeedbackDiagnosticMessage(javascriptErrorMessage(event.error, event.message || 'JavaScript error'));
            if (message !== '') {
                recordFeedbackDiagnostic(userId, {
                    type: 'javascript_error',
                    occurred_at: new Date().toISOString(),
                    message,
                });
            }
        };

        const handleRejection = (event: PromiseRejectionEvent) => {
            const message = sanitizeFeedbackDiagnosticMessage(javascriptErrorMessage(event.reason, 'Unhandled promise rejection'));
            if (message !== '') {
                recordFeedbackDiagnostic(userId, {
                    type: 'javascript_error',
                    occurred_at: new Date().toISOString(),
                    message,
                });
            }
        };

        window.addEventListener('error', handleError);
        window.addEventListener('unhandledrejection', handleRejection);

        return () => {
            removeFinish();
            axios.interceptors.response.eject(interceptorId);
            window.removeEventListener('error', handleError);
            window.removeEventListener('unhandledrejection', handleRejection);
        };
    }, [userId]);
}
