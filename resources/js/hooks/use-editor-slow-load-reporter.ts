import axios from 'axios';
import { useEffect, useRef } from 'react';

import { editorLoadSlowUrl, getEditorLoadElapsedMs, hasReportedSlowEditorLoad, markSlowEditorLoadReported } from '@/lib/editor-load';
import type { EditorClientLoadStage, EditorLoadContext } from '@/types/editor-load';

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

        const remainingMs = Math.max(0, context.slowThresholdMs - getEditorLoadElapsedMs(context.token));
        const timeout = window.setTimeout(() => {
            if (hasReportedSlowEditorLoad(context.token)) {
                return;
            }

            markSlowEditorLoadReported(context.token);
            void axios
                .post(editorLoadSlowUrl(context.token), {
                    stage: stageRef.current,
                    progress: Math.max(0, Math.min(100, Math.round(progressRef.current))),
                })
                .catch(() => {
                    // Logging must never block or fail the editor navigation.
                });
        }, remainingMs);

        return () => window.clearTimeout(timeout);
    }, [active, context]);
}
