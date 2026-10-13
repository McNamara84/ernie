import { createContext, useCallback, useContext, useRef, useState } from 'react';

export interface RorInputDraft {
    text: string;
    section: 'authors' | 'contributors' | 'fundingReferences';
    editingIndex?: number;
}

export function useRorInputDrafts() {
    const current = useRef<Record<string, RorInputDraft>>({});
    const [drafts, setDrafts] = useState(current.current);
    const set = useCallback((id: string, draft: RorInputDraft | null) => {
        const next = { ...current.current };
        if (draft) next[id] = draft;
        else delete next[id];
        current.current = next;
        setDrafts(next);
    }, []);
    const retain = useCallback((ids: Set<string>) => {
        const next = Object.fromEntries(Object.entries(current.current).filter(([id]) => ids.has(id)));
        if (Object.keys(next).length !== Object.keys(current.current).length) {
            current.current = next;
            setDrafts(next);
        }
    }, []);
    return { drafts, current, set, retain };
}

export const RorInputDraftContext = createContext<ReturnType<typeof useRorInputDrafts> | null>(null);

/** The editor owns drafts across accordion unmounts; standalone fields keep a local draft. */
export function useRorInputDraft(id: string) {
    const registry = useContext(RorInputDraftContext);
    const local = useRorInputDrafts();
    const store = registry ?? local;
    return {
        draft: store.drafts[id],
        get: () => store.current.current[id],
        set: (draft: RorInputDraft | null) => store.set(id, draft),
    };
}
