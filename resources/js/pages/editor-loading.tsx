import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

import { EditorLoadingModal } from '@/components/editor/editor-loading-modal';
import { useEditorLoadTimeline } from '@/hooks/use-editor-load-timeline';
import { useEditorSlowLoadReporter } from '@/hooks/use-editor-slow-load-reporter';
import AppLayout from '@/layouts/app-layout';
import { EDITOR_LOAD_TOKEN_HEADER, editorLoadStatusUrl } from '@/lib/editor-load';
import { editor } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { EditorLoadContext, EditorLoadStatus } from '@/types/editor-load';

interface EditorLoadingPageProps {
    editorLoad: EditorLoadContext;
    loadError?: string | null;
}

const STATUS_POLL_INTERVAL_MS = 400;

export default function EditorLoadingPage({ editorLoad, loadError = null }: EditorLoadingPageProps) {
    const [progress, setProgress] = useState(editorLoad.serverProgress);
    const [error, setError] = useState<string | null>(loadError);
    const visitStarted = useRef(false);
    const { message } = useEditorLoadTimeline(editorLoad.token);

    useEditorSlowLoadReporter(editorLoad, error === null, 'loader', progress);

    const retry = useCallback((): void => window.location.reload(), []);
    const goBack = useCallback((): void => {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        router.visit('/resources');
    }, []);

    useEffect(() => {
        if (error !== null) {
            return;
        }

        let disposed = false;

        const loadStatus = async (): Promise<void> => {
            try {
                const response = await axios.get<EditorLoadStatus>(editorLoadStatusUrl(editorLoad.token));
                if (disposed) return;

                setProgress((current) => Math.max(current, response.data.progress));
                if (response.data.status === 'failed') {
                    setError(response.data.error ?? 'Unable to load this resource in the Data Editor. Please try again.');
                }
            } catch {
                // Progress polling is best effort. The Inertia request remains authoritative.
            }
        };

        void loadStatus();
        const interval = window.setInterval(() => void loadStatus(), STATUS_POLL_INTERVAL_MS);

        return () => {
            disposed = true;
            window.clearInterval(interval);
        };
    }, [editorLoad.token, error]);

    useEffect(() => {
        if (error !== null || visitStarted.current) {
            return;
        }

        visitStarted.current = true;
        const target = `${window.location.pathname}${window.location.search}`;
        const showNavigationError = (): void => {
            setError('Unable to load this resource in the Data Editor. Check your connection and try again.');
        };

        router.visit(target, {
            method: 'get',
            replace: true,
            preserveScroll: true,
            preserveState: false,
            showProgress: false,
            headers: {
                [EDITOR_LOAD_TOKEN_HEADER]: editorLoad.token,
            },
            onError: showNavigationError,
            onNetworkError: () => {
                showNavigationError();
                return false;
            },
            onHttpException: () => {
                showNavigationError();
                return false;
            },
        });
    }, [editorLoad.token, error]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Editor', href: editor().url }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Loading Data Editor" />
            <div className="min-h-[50vh]" aria-hidden="true" />
            <EditorLoadingModal progress={progress} message={message} error={error} onRetry={retry} onGoBack={goBack} />
        </AppLayout>
    );
}
