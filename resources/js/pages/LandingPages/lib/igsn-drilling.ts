import type {
    LandingPageContributor,
    LandingPageFundingReference,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

import { findDateByType } from './dateHelpers';

export interface IgsnDrillingMetadata {
    collectionMethod: string | null;
    collectionMethodDescription: string | null;
    totalLength: string | null;
    comments: string | null;
    platformType: string | null;
    platformName: string | null;
    platformDescription: string | null;
    operators: string | null;
    fundingAgencies: string | null;
    chiefScientists: string | null;
    samplingDate: string | null;
    startDate: string | null;
    endDate: string | null;
}

export const normalizeIgsnDisplayValue = (value: string | null | undefined): string | null => {
    const normalized = value?.trim();

    return normalized && normalized.toLowerCase() !== 'n/a' ? normalized : null;
};

export const uniqueIgsnDisplayValues = (values: Array<string | null | undefined>): string[] => {
    const unique = new Map<string, string>();

    for (const value of values) {
        const normalized = normalizeIgsnDisplayValue(value);
        if (normalized && !unique.has(normalized.toLowerCase())) {
            unique.set(normalized.toLowerCase(), normalized);
        }
    }

    return Array.from(unique.values());
};

export const trimIgsnNumber = (value: string): string => (value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value);

const formatContributorName = (contributor: LandingPageContributor): string | null => {
    const entity = contributor.contributorable;
    if (entity.type === 'Institution') {
        return normalizeIgsnDisplayValue(entity.name);
    }

    return normalizeIgsnDisplayValue([entity.given_name?.trim(), entity.family_name?.trim()].filter(Boolean).join(' '));
};

export function buildIgsnDrillingMetadata(
    igsn: LandingPageIgsnMetadata | null | undefined,
    contributors: LandingPageContributor[],
    fundingReferences: LandingPageFundingReference[],
    dates: LandingPageResourceDate[],
): IgsnDrillingMetadata {
    const collectedDates = dates.filter((date) => date.date_type_slug === 'Collected' || date.date_type === 'Collected');
    const samplingDate = collectedDates.find((date) => date.date_information?.toLowerCase().includes('legacy igsn sampling date'));
    const collectionDate =
        collectedDates.find((date) => date.date_information?.toLowerCase().includes('legacy igsn collection period')) ??
        collectedDates.find((date) => date.start_date || date.end_date) ??
        findDateByType(dates, 'Collected');

    const totalLengths = (igsn?.total_lengths ?? [])
        .map((length) => {
            const value = normalizeIgsnDisplayValue(length.numeric_value);
            if (!value) return null;

            const unit = normalizeIgsnDisplayValue(length.unit);
            return `${trimIgsnNumber(value)}${unit ? ` ${unit}` : ''}`;
        })
        .filter((value): value is string => value !== null);

    const chiefScientists = contributors
        .filter((contributor) =>
            contributor.contributor_types.some((type) => {
                const normalized = type.trim().toLowerCase();
                return normalized === 'data collector' || normalized === 'datacollector';
            }),
        )
        .map(formatContributorName);

    return {
        collectionMethod: normalizeIgsnDisplayValue(igsn?.collection_method),
        collectionMethodDescription: normalizeIgsnDisplayValue(igsn?.collection_method_description),
        totalLength: uniqueIgsnDisplayValues(totalLengths).join('; ') || null,
        comments: uniqueIgsnDisplayValues(igsn?.comments ?? []).join('; ') || null,
        platformType: normalizeIgsnDisplayValue(igsn?.platform_type),
        platformName: normalizeIgsnDisplayValue(igsn?.platform_name),
        platformDescription: normalizeIgsnDisplayValue(igsn?.platform_description),
        operators: uniqueIgsnDisplayValues(igsn?.operators ?? []).join('; ') || null,
        fundingAgencies: uniqueIgsnDisplayValues(fundingReferences.map((funding) => funding.funder_name)).join(', ') || null,
        chiefScientists: uniqueIgsnDisplayValues(chiefScientists).join(', ') || null,
        samplingDate: normalizeIgsnDisplayValue(samplingDate?.date_value),
        startDate: normalizeIgsnDisplayValue(collectionDate?.start_date ?? collectionDate?.date_value),
        endDate: normalizeIgsnDisplayValue(collectionDate?.end_date ?? collectionDate?.date_value),
    };
}
