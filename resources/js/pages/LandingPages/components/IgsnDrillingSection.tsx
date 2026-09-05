import type { ReactNode } from 'react';

import type { LandingPageContributor, LandingPageFundingReference, LandingPageIgsnMetadata, LandingPageResourceDate } from '@/types/landing-page';

import { buildIgsnDrillingMetadata } from '../lib/igsn-drilling';
import { LandingPageCard } from './LandingPageCard';
import { MetadataList, type MetadataRow } from './MetadataList';

interface IgsnDrillingSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
    contributors: LandingPageContributor[];
    fundingReferences: LandingPageFundingReference[];
    dates: LandingPageResourceDate[];
}

export function IgsnDrillingSection({ igsn, contributors, fundingReferences, dates }: IgsnDrillingSectionProps): ReactNode {
    const drilling = buildIgsnDrillingMetadata(igsn, contributors, fundingReferences, dates);

    const rows: MetadataRow[] = [
        { label: 'Collection Method', value: drilling.collectionMethod },
        { label: 'Collection Method Description', value: drilling.collectionMethodDescription },
        { label: 'Total Length', value: drilling.totalLength },
        { label: 'Comments', value: drilling.comments ? <span className="whitespace-pre-line">{drilling.comments}</span> : null },
        { label: 'Platform Type', value: drilling.platformType },
        { label: 'Platform Name', value: drilling.platformName },
        { label: 'Platform Description', value: drilling.platformDescription },
        { label: 'Operator', value: drilling.operators },
        { label: 'Funding Agency', value: drilling.fundingAgencies },
        { label: 'Chief Scientist', value: drilling.chiefScientists },
        { label: 'Sampling Date', value: drilling.samplingDate },
        { label: 'Start Date', value: drilling.startDate },
        { label: 'End Date', value: drilling.endDate },
    ];

    if (!rows.some((row) => row.value)) return null;

    return (
        <LandingPageCard aria-labelledby="heading-igsn-drilling">
            <h2 id="heading-igsn-drilling" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Drilling
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
