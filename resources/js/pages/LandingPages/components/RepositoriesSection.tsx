import type { ReactNode } from 'react';

import type { LandingPageIgsnMetadata } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

interface RepositoriesSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
}

export function RepositoriesSection({ igsn }: RepositoriesSectionProps): ReactNode {
    const rows: MetadataRow[] = [
        { label: 'Current Repository', value: igsn?.current_archive ?? null },
        { label: 'Current Repository Contact', value: igsn?.current_archive_contact ?? null },
        { label: 'Original Repository', value: igsn?.original_archive ?? null },
        { label: 'Original Repository Contact', value: igsn?.original_archive_contact ?? null },
        { label: 'Sample Access', value: igsn?.sample_access ?? null },
    ];

    if (!hasVisibleMetadataRows(rows)) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-repositories">
            <h2 id="heading-repositories" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Repositories
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
