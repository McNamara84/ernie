import { type Dispatch, type SetStateAction, useCallback, useEffect, useRef, useState } from 'react';

import { apiRequest } from '@/lib/api-client';
import { apiEndpoints } from '@/lib/query-keys';
import type { InstrumentSelection } from '@/types';

/** Runs at form level, including while the instruments accordion is closed. */
export function useInstrumentNameResolution(instruments: InstrumentSelection[], setInstruments: Dispatch<SetStateAction<InstrumentSelection[]>>) {
    const [refresh, setRefresh] = useState(0);
    const handledRefresh = useRef(0);
    const [isResolving, setIsResolving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const force = refresh !== handledRefresh.current;
        handledRefresh.current = refresh;
        if (instruments.length === 0 || (!force && instruments.every((instrument) => instrument.resolution))) {
            setIsResolving(false);
            return;
        }
        const controller = new AbortController();
        setIsResolving(true);
        setError(null);
        void apiRequest<{ instruments: InstrumentSelection[] }>(apiEndpoints.resolveInstrumentNames, {
            method: 'POST',
            signal: controller.signal,
            body: { instruments: instruments.map(({ pid, pidType, name }) => ({ pid, pidType, name })) },
        })
            .then((result) => {
                if (controller.signal.aborted) return;
                if (!Array.isArray(result?.instruments) || result.instruments.length !== instruments.length) {
                    throw new Error('Invalid instrument resolution response.');
                }
                const resolved = instruments.map((instrument, index) => {
                    const replacement = result.instruments[index];
                    if (
                        replacement.pid !== instrument.pid.trim() ||
                        replacement.pidType !== instrument.pidType ||
                        typeof replacement.name !== 'string' ||
                        !replacement.resolution
                    ) {
                        throw new Error('Invalid instrument resolution response.');
                    }
                    // The resolver supplies labels and diagnostics, never replacement identifiers.
                    const name = replacement.name === instrument.name.trim() ? instrument.name : replacement.name;
                    return { ...instrument, name, resolution: replacement.resolution };
                });
                setInstruments((current) => (current === instruments ? resolved : current));
            })
            .catch((cause: unknown) => {
                if (!controller.signal.aborted) {
                    setError(cause instanceof Error ? cause.message : 'Unable to resolve instrument names.');
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setIsResolving(false);
            });
        return () => controller.abort();
    }, [instruments, setInstruments, refresh]);

    const resolveAgain = useCallback(() => {
        if (!isResolving) setRefresh((value) => value + 1);
    }, [isResolving]);

    return { isResolving, resolutionError: error, resolveAgain };
}
