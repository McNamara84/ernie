import type { ReactNode } from 'react';

import type { LandingPageIgsnMetadata } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';
import { MetadataList, type MetadataRow } from './MetadataList';

interface IgsnMethodsSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
}

export function IgsnMethodsSection({ igsn }: IgsnMethodsSectionProps): ReactNode {
    const rows: MetadataRow[] = [];
    const seen = new Set<string>();

    for (const method of igsn?.methods ?? []) {
        const value = method.value?.trim();
        if (!value) continue;
        const label = method.scheme?.trim() || 'Method';
        const key = `${label.toLowerCase()}|${value.toLowerCase()}`;
        if (seen.has(key)) continue;
        seen.add(key);
        rows.push({ key, label, value });
    }

    if (rows.length === 0) return null;

    return (
        <LandingPageCard aria-labelledby="heading-igsn-methods">
            <h2 id="heading-igsn-methods" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Methods
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
