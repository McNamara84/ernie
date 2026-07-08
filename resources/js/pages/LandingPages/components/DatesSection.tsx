import type { ReactNode } from 'react';

import type { LandingPageResourceDate } from '@/types/landing-page';

import { formatLandingPageDate, isCoverageDate } from '../lib/dateHelpers';
import { LandingPageCard } from './LandingPageCard';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

interface DatesSectionProps {
    dates: LandingPageResourceDate[];
}

const getDateLabel = (date: LandingPageResourceDate): string => {
    const label = date.date_type?.trim() || date.date_type_slug?.trim();
    return label || 'Date';
};

const buildDateValue = (date: LandingPageResourceDate): ReactNode | null => {
    const formattedDate = formatLandingPageDate(date);

    if (!formattedDate) {
        return null;
    }

    const information = date.date_information?.trim();

    if (!information) {
        return formattedDate;
    }

    return (
        <span>
            <span className="block">{formattedDate}</span>
            <span className="mt-1 block text-xs text-gray-600 dark:text-gray-400">{information}</span>
        </span>
    );
};

export function DatesSection({ dates }: DatesSectionProps): ReactNode {
    const seenLabels = new Map<string, number>();
    const rows: MetadataRow[] = dates
        .filter((date) => !isCoverageDate(date))
        .map((date) => {
            const baseLabel = getDateLabel(date);
            const count = (seenLabels.get(baseLabel) ?? 0) + 1;
            seenLabels.set(baseLabel, count);

            return {
                label: count > 1 ? `${baseLabel} ${count}` : baseLabel,
                value: buildDateValue(date),
            };
        });

    if (!hasVisibleMetadataRows(rows)) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-dates">
            <h2 id="heading-dates" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Dates
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
