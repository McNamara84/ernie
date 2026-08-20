import { useEffect, useState } from 'react';

import { EDITOR_LOADING_MESSAGES, getEditorLoadElapsedMs } from '@/lib/editor-load';

const MESSAGE_INTERVAL_MS = 2_000;
const CLOCK_UPDATE_INTERVAL_MS = 250;

interface EditorLoadTimeline {
    elapsedMs: number;
    message: (typeof EDITOR_LOADING_MESSAGES)[number];
    messageIndex: number;
}

export function useEditorLoadTimeline(token: string | null): EditorLoadTimeline {
    const [elapsedMs, setElapsedMs] = useState(() => (token === null ? 0 : getEditorLoadElapsedMs(token)));

    useEffect(() => {
        if (token === null) {
            setElapsedMs(0);
            return;
        }

        const updateElapsed = (): void => setElapsedMs(getEditorLoadElapsedMs(token));
        updateElapsed();
        const interval = window.setInterval(updateElapsed, CLOCK_UPDATE_INTERVAL_MS);

        return () => window.clearInterval(interval);
    }, [token]);

    const messageIndex = Math.min(Math.floor(elapsedMs / MESSAGE_INTERVAL_MS), EDITOR_LOADING_MESSAGES.length - 1);

    return {
        elapsedMs,
        messageIndex,
        message: EDITOR_LOADING_MESSAGES[messageIndex],
    };
}
