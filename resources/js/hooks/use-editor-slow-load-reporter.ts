import axios from 'axios';
import { useEffect, useRef } from 'react';

import { editorLoadSlowUrl, getEditorLoadElapsedMs, hasReportedSlowEditorLoad, markSlowEditorLoadReported } from '@/lib/editor-load';
import type { EditorClientLoadStage, EditorLoadContext } from '@/types/editor-load';

const MAX_REPORT_ATTEMPTS = 3;
const RETRY_DELAY_MS = 1_000;

export function useEditorSlowLoadReporter(
    context: EditorLoadContext | undefined,
    active: boolean,
    stage: EditorClientLoadStage,
    progress: number,
): void {
    const stageRef = useRef(stage);
    const progressRef = useRef(progress);

    useEffect(() => {
        stageRef.current = stage;
        progressRef.current = progress;
    }, [progress, stage]);

    useEffect(() => {
        if (!context || !active || hasReportedSlowEditorLoad(context.token)) {
            return;
        }

        let cancelled = false;
        let retryTimeout: number | undefined;

        const reportSlowLoad = (attempt: number): void => {
            if (cancelled || hasReportedSlowEditorLoad(context.token)) {
                return;
            }

            void axios
                .post(editorLoadSlowUrl(context.token), {
                    stage: stageRef.current,
                    progress: Math.max(0, Math.min(100, Math.round(progressRef.current))),
                })
                .then(() => markSlowEditorLoadReported(context.token))
                .catch(() => {
                    if (!cancelled && attempt < MAX_REPORT_ATTEMPTS) {
                        retryTimeout = window.setTimeout(() => reportSlowLoad(attempt + 1), RETRY_DELAY_MS);
                    }
                });
        };

        const remainingMs = Math.max(0, context.slowThresholdMs - getEditorLoadElapsedMs(context.token));
        const timeout = window.setTimeout(() => {
            reportSlowLoad(1);
        }, remainingMs);

        return () => {
            cancelled = true;
            window.clearTimeout(timeout);
            if (retryTimeout !== undefined) {
                window.clearTimeout(retryTimeout);
            }
        };
    }, [active, context]);
}
