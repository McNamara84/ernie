import type { ReactNode } from 'react';

import type {
    LandingPageContributor,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageIgsnClassification,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

import { buildIgsnDrillingMetadata, normalizeIgsnDisplayValue, trimIgsnNumber, uniqueIgsnDisplayValues } from '../lib/igsn-drilling';
import { IgsnDescriptionGroups } from './IgsnDescriptionGroups';
import { LandingPageCard } from './LandingPageCard';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

interface AcquisitionSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
    classifications: LandingPageIgsnClassification[];
    descriptions: LandingPageDescription[];
    contributors: LandingPageContributor[];
    fundingReferences: LandingPageFundingReference[];
    dates: LandingPageResourceDate[];
    separateDrillingMetadata?: boolean;
}

const dedup = <T,>(values: T[]): T[] => Array.from(new Set(values));

const mergeUniqueText = (values: Array<string | null | undefined>): string[] => {
    const unique = new Map<string, string>();

    values.forEach((value) => {
        const trimmed = value?.trim();
        if (trimmed) {
            const key = trimmed.toLowerCase();
            if (!unique.has(key)) {
                unique.set(key, trimmed);
            }
        }
    });

    return Array.from(unique.values());
};

/**
 * "Acquisition" module for IGSN landing pages — collection-context metadata.
 *
 * Returns `null` when no field has data so the wrapping card is omitted.
 */
interface MeasurementRange {
    start: string | null;
    end: string | null;
    unit: string | null;
    end_unit: string | null;
}

const formatRange = (range: MeasurementRange): string | null => {
    const start = normalizeIgsnDisplayValue(range.start);
    const end = normalizeIgsnDisplayValue(range.end);
    if (!start && !end) return null;

    const startValue = start ? trimIgsnNumber(start) : null;
    const endValue = end ? trimIgsnNumber(end) : null;
    const startUnit = normalizeIgsnDisplayValue(range.unit);
    const endUnit = normalizeIgsnDisplayValue(range.end_unit) ?? startUnit;
    if (startValue && endValue && startUnit === endUnit) {
        return `${startValue} – ${endValue}${startUnit ? ` ${startUnit}` : ''}`;
    }

    const startLabel = startValue ? `${startValue}${startUnit ? ` ${startUnit}` : ''}` : null;
    const endLabel = endValue ? `${endValue}${endUnit ? ` ${endUnit}` : ''}` : null;
    return startLabel && endLabel ? `${startLabel} – ${endLabel}` : (startLabel ?? endLabel);
};

export function AcquisitionSection({
    igsn,
    classifications,
    contributors,
    fundingReferences,
    dates,
    separateDrillingMetadata = false,
}: AcquisitionSectionProps): ReactNode {
    const classification = dedup(
        classifications
            .map((classification) => classification.value)
            .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
            .map((value) => value.trim()),
    ).join(', ');

    const descriptionGroups =
        igsn?.description_groups && igsn.description_groups.length > 0
            ? igsn.description_groups
            : igsn?.material_descriptions?.length
              ? [{ entries: mergeUniqueText(igsn.material_descriptions).map((value) => ({ value, scheme: null })) }]
              : [];

    const geologicalUnits = dedup((igsn?.geological_units ?? []).map((unit) => unit.value.trim()).filter(Boolean)).join(', ');
    const geologicalAges = dedup((igsn?.geological_ages ?? []).map((age) => age.value.trim()).filter(Boolean)).join(', ');
    const rockTypes = dedup((igsn?.field_names ?? []).map((value) => value.trim()).filter(Boolean)).join(', ');
    const classificationComments = dedup((igsn?.classification_comments ?? []).map((value) => value.trim()).filter(Boolean)).join('; ');
    const ageRanges = (igsn?.age_ranges ?? [])
        .map(formatRange)
        .filter((value): value is string => value !== null)
        .join('; ');
    const launchPlatforms = uniqueIgsnDisplayValues(igsn?.launch_platform_names ?? []).join('; ');
    const launchTypes = uniqueIgsnDisplayValues(igsn?.launch_type_names ?? []).join('; ');
    const navigationTypes = uniqueIgsnDisplayValues(igsn?.navigation_types ?? []).join('; ');
    const sizes = (igsn?.sizes ?? [])
        .map((size) => {
            const type = size.type ? size.type.charAt(0).toUpperCase() + size.type.slice(1) : 'Size';
            const numericValue = size.numeric_value?.includes('.') ? size.numeric_value.replace(/0+$/, '').replace(/\.$/, '') : size.numeric_value;
            return `${type}: ${numericValue ?? ''}${size.unit ? ` ${size.unit}` : ''}`.trim();
        })
        .join('; ');

    const drilling = buildIgsnDrillingMetadata(igsn, contributors, fundingReferences, dates);
    const material = igsn?.material === 'NotApplicable' ? 'Not applicable' : igsn?.material?.trim() || null;
    const materialLabel = material ?? 'Material';
    const missingValue = igsn ? 'N/A' : null;
    const valueOrMissing = (value: ReactNode): ReactNode => value ?? missingValue;

    const leadingRows: MetadataRow[] = [
        { label: 'Material', value: valueOrMissing(material) },
        { label: `${materialLabel} Classification`, value: valueOrMissing(classification || null) },
        { label: 'Rock Type', value: valueOrMissing(rockTypes || null) },
    ];
    const detailRows: MetadataRow[] = [
        { label: 'Classification Comments', value: valueOrMissing(classificationComments || null) },
        { label: 'Geological Age', value: valueOrMissing(geologicalAges || null) },
        { label: 'Age Range', value: valueOrMissing(ageRanges || null) },
        { label: 'Geological Unit', value: valueOrMissing(geologicalUnits || null) },
        { label: 'Minimum Depth', value: valueOrMissing(igsn?.depth_min ?? null) },
        { label: 'Maximum Depth', value: valueOrMissing(igsn?.depth_max ?? null) },
        { label: 'Depth Scale', value: valueOrMissing(igsn?.depth_scale ?? null) },
        { label: 'Sizes', value: valueOrMissing(sizes || null) },
        { label: 'Launch Platform', value: valueOrMissing(launchPlatforms || null) },
        { label: 'Launch Type', value: valueOrMissing(launchTypes || null) },
        { label: 'Navigation Type', value: valueOrMissing(navigationTypes || null) },
    ];
    const drillingRows: MetadataRow[] = separateDrillingMetadata
        ? []
        : [
              { label: 'Collection Method', value: valueOrMissing(drilling.collectionMethod) },
              { label: 'Collection Method Description', value: valueOrMissing(drilling.collectionMethodDescription) },
              { label: 'Total Length', value: valueOrMissing(drilling.totalLength) },
              {
                  label: 'Comments',
                  value: valueOrMissing(drilling.comments ? <span className="whitespace-pre-line">{drilling.comments}</span> : null),
              },
              { label: 'Platform Type', value: valueOrMissing(drilling.platformType) },
              { label: 'Platform Name', value: valueOrMissing(drilling.platformName) },
              { label: 'Platform Description', value: valueOrMissing(drilling.platformDescription) },
              { label: 'Operator', value: valueOrMissing(drilling.operators) },
              { label: 'Funding Agency', value: valueOrMissing(drilling.fundingAgencies) },
              { label: 'Chief Scientist', value: valueOrMissing(drilling.chiefScientists) },
              { label: 'Sampling Date', value: valueOrMissing(drilling.samplingDate) },
              { label: 'Start Date', value: valueOrMissing(drilling.startDate) },
              { label: 'End Date', value: valueOrMissing(drilling.endDate) },
          ];
    const rows = [...leadingRows, ...detailRows, ...drillingRows];

    const hasContent = hasVisibleMetadataRows(rows);

    if (!hasContent) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-acquisition">
            <h2 id="heading-acquisition" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Acquisition
            </h2>
            <div data-slot="acquisition-metadata-grid" className="grid grid-cols-[fit-content(12rem)_minmax(0,1fr)] gap-x-4 gap-y-3">
                <MetadataList rows={leadingRows} subgrid />
                <IgsnDescriptionGroups groups={descriptionGroups} subgrid />
                <MetadataList rows={[...detailRows, ...drillingRows]} subgrid />
            </div>
        </LandingPageCard>
    );
}
