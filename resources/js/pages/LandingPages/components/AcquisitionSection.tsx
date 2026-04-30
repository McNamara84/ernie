import type { ReactNode } from 'react';

import type {
    LandingPageContributor,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageIgsnClassification,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

import { findDateByType } from '../lib/dateHelpers';
import { LandingPageCard } from './LandingPageCard';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

interface AcquisitionSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
    classifications: LandingPageIgsnClassification[];
    descriptions: LandingPageDescription[];
    contributors: LandingPageContributor[];
    fundingReferences: LandingPageFundingReference[];
    dates: LandingPageResourceDate[];
}

/**
 * Build a "Given Family" display name from a contributor's contributorable.
 */
const formatContributorName = (contributor: LandingPageContributor): string | null => {
    const entity = contributor.contributorable;
    if (entity.type === 'Institution') {
        return entity.name?.trim() || null;
    }
    const given = entity.given_name?.trim();
    const family = entity.family_name?.trim();
    const combined = [given, family].filter(Boolean).join(' ');
    return combined || null;
};

const dedup = <T,>(values: T[]): T[] => Array.from(new Set(values));

/**
 * "Acquisition" module for IGSN landing pages — collection-context metadata.
 *
 * Returns `null` when no field has data so the wrapping card is omitted.
 */
export function AcquisitionSection({
    igsn,
    classifications,
    descriptions,
    contributors,
    fundingReferences,
    dates,
}: AcquisitionSectionProps): ReactNode {
    const rockClassification = dedup(
        classifications
            .map((classification) => classification.value)
            .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
            .map((value) => value.trim()),
    ).join(', ');

    const fundingAgency = dedup(
        fundingReferences
            .map((funding) => funding.funder_name)
            .filter((name): name is string => typeof name === 'string' && name.trim() !== '')
            .map((name) => name.trim()),
    ).join(', ');

    const otherDescription = descriptions.find(
        (description) => description.description_type?.toLowerCase() === 'other',
    );
    const trimmedComment = otherDescription?.value?.trim();
    const comments = trimmedComment
        ? <span className="whitespace-pre-line">{trimmedComment}</span>
        : null;

    const chiefScientists = dedup(
        contributors
            .filter((contributor) =>
                contributor.contributor_types.some(
                    (type) => type.toLowerCase() === 'data collector' || type.toLowerCase() === 'datacollector',
                ),
            )
            .map(formatContributorName)
            .filter((name): name is string => name !== null),
    ).join(', ');

    const collectedDate = findDateByType(dates, 'Collected');
    const startDate = collectedDate?.start_date ?? collectedDate?.date_value ?? null;
    const endDateRaw = collectedDate?.end_date ?? null;
    // Hide end date if equal to start date (single-day collection rendered as "Start Date" only).
    const endDate = endDateRaw !== null && endDateRaw !== startDate ? endDateRaw : null;

    const collectionMethod = igsn?.collection_method?.trim() || null;
    const collectionMethodDescription = igsn?.collection_method_description?.trim() || null;
    let collectionMethodNode: ReactNode = collectionMethod;
    if (collectionMethod && collectionMethodDescription) {
        collectionMethodNode = (
            <span>
                {collectionMethod}
                <span className="mt-1 block text-xs text-gray-600 dark:text-gray-400">
                    {collectionMethodDescription}
                </span>
            </span>
        );
    }

    const rows: MetadataRow[] = [
        { label: 'Material', value: igsn?.material ?? null },
        { label: 'Rock Classification', value: rockClassification || null },
        { label: 'Collection Method', value: collectionMethodNode },
        { label: 'Funding Agency', value: fundingAgency || null },
        { label: 'Comments', value: comments },
        { label: 'Chief Scientist', value: chiefScientists || null },
        { label: 'Start Date', value: startDate },
        { label: 'End Date', value: endDate },
    ];

    const hasContent = hasVisibleMetadataRows(rows);

    if (!hasContent) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-acquisition">
            <h2 id="heading-acquisition" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Acquisition
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
