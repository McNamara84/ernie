import type { ReactNode } from 'react';

import type { LandingPageIgsnMetadata } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';
import { MetadataList, type MetadataRow } from './MetadataList';

interface IgsnDrillingSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
}

interface MeasurementRange {
    start: string | null;
    end: string | null;
    unit: string | null;
    end_unit: string | null;
}

const trimNumber = (value: string): string => (value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value);

const formatRange = (range: MeasurementRange): string | null => {
    const start = range.start?.trim();
    const end = range.end?.trim();
    if (!start && !end) return null;

    const startValue = start ? trimNumber(start) : null;
    const endValue = end ? trimNumber(end) : null;
    const endUnit = range.end_unit ?? range.unit;
    if (startValue && endValue && range.unit === endUnit) {
        return `${startValue} – ${endValue}${range.unit ? ` ${range.unit}` : ''}`;
    }

    const startLabel = startValue ? `${startValue}${range.unit ? ` ${range.unit}` : ''}` : null;
    const endLabel = endValue ? `${endValue}${endUnit ? ` ${endUnit}` : ''}` : null;
    return startLabel && endLabel ? `${startLabel} – ${endLabel}` : (startLabel ?? endLabel);
};

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
        .map(formatRange)
        .filter((value): value is string => value !== null)
        .join('; ');

    const elevationRanges = (igsn?.elevation_ranges ?? [])
        .map(formatRange)
        .filter((value): value is string => value !== null)
        .join('; ');

    const joinValues = (values: string[] | undefined): string | null => {
        const unique = Array.from(new Set((values ?? []).map((value) => value.trim()).filter(Boolean)));
        return unique.length > 0 ? unique.join('; ') : null;
    };

    const rows: MetadataRow[] = [
        { label: 'Total Length', value: totalLengths || null },
        { label: 'Age Range', value: ageRanges || null },
        { label: 'Elevation Range', value: elevationRanges || null },
        { label: 'Launch Platform', value: joinValues(igsn?.launch_platform_names) },
        { label: 'Launch Type', value: joinValues(igsn?.launch_type_names) },
        { label: 'Navigation Type', value: joinValues(igsn?.navigation_types) },
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
