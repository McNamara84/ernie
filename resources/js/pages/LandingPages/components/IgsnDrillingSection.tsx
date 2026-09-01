import type { ReactNode } from 'react';

import type { LandingPageIgsnMetadata } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';
import { MetadataList, type MetadataRow } from './MetadataList';

interface IgsnDrillingSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
}

const trimNumber = (value: string): string => (value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value);

export function IgsnDrillingSection({ igsn }: IgsnDrillingSectionProps): ReactNode {
    const totalLengths = (igsn?.total_lengths ?? [])
        .map((length) => {
            const value = length.numeric_value?.trim();
            if (!value) return null;
            return `${trimNumber(value)}${length.unit ? ` ${length.unit}` : ''}`;
        })
        .filter((value): value is string => value !== null)
        .join('; ');

    const ageRanges = (igsn?.age_ranges ?? [])
        .map((range) => {
            const start = range.start?.trim();
            const end = range.end?.trim();
            if (!start && !end) return null;
            const values = start && end ? `${start} – ${end}` : (start ?? end);
            return `${values}${range.unit ? ` ${range.unit}` : ''}`;
        })
        .filter((value): value is string => value !== null)
        .join('; ');

    const rows: MetadataRow[] = [
        { label: 'Total Length', value: totalLengths || null },
        { label: 'Age Range', value: ageRanges || null },
    ];

    if (!totalLengths && !ageRanges) return null;

    return (
        <LandingPageCard aria-labelledby="heading-igsn-drilling">
            <h2 id="heading-igsn-drilling" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Drilling
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
