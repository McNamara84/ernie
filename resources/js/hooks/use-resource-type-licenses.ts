import { useEffect, useState } from 'react';

import type { License } from '@/types';

/** Load editor-specific choices without modifying already selected rights. */
export function useResourceTypeLicenses(licenses: License[], resourceTypeId: string) {
    const [result, setResult] = useState<{ resourceTypeId: string; choices: License[]; error: boolean } | null>(null);

    useEffect(() => {
        if (!resourceTypeId) return;

        const controller = new AbortController();
        let current = true;
        void fetch(`/api/v1/licenses/ernie?resource_type_id=${encodeURIComponent(resourceTypeId)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error('License choices could not be loaded.');
                const choices: License[] = await response.json();
                if (!Array.isArray(choices)) throw new Error('Invalid license choices.');
                if (current) setResult({ resourceTypeId, choices, error: false });
            })
            .catch(() => {
                if (current) setResult({ resourceTypeId, choices: [], error: true });
            });

        return () => {
            current = false;
            controller.abort();
        };
    }, [resourceTypeId]);

    const loaded = result?.resourceTypeId === resourceTypeId;
    return {
        choices: resourceTypeId ? (loaded ? result.choices : []) : licenses,
        error: Boolean(resourceTypeId && loaded && result.error),
        loading: Boolean(resourceTypeId && !loaded),
    };
}
