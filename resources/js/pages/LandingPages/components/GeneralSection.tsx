import type { ReactNode } from 'react';

import type {
    LandingPageFundingReference,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

import { findDateByType, pickDateString } from '../lib/dateHelpers';
import { LandingPageCard } from './LandingPageCard';
import { MetadataList, type MetadataRow } from './MetadataList';

interface GeneralSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
    /** The IGSN value (resource DOI for PhysicalObjects). */
    doi: string | null | undefined;
    fundingReferences: LandingPageFundingReference[];
    dates: LandingPageResourceDate[];
}

/**
 * "General" module for IGSN landing pages — sample-level descriptive metadata.
 *
 * Renders nothing when no field has data so the surrounding layout collapses
 * gracefully on incomplete records.
 */
export function GeneralSection({ igsn, doi, fundingReferences, dates }: GeneralSectionProps): ReactNode {
    const project = fundingReferences
        .map((funding) => funding.award_title)
        .filter((title): title is string => typeof title === 'string' && title.trim() !== '')
        .filter((title, index, all) => all.indexOf(title) === index)
        .join(', ');

    const releaseDate = pickDateString(findDateByType(dates, 'Available'));

    let parentNode: ReactNode = null;
    if (igsn?.parent?.doi) {
        parentNode = igsn.parent.landing_page ? (
            <a
                href={igsn.parent.landing_page.public_url}
                className="text-gfz-primary underline hover:no-underline dark:text-blue-400"
            >
                {igsn.parent.doi}
            </a>
        ) : (
            igsn.parent.doi
        );
    }

    const purpose = igsn?.sample_purpose
        ? <span className="whitespace-pre-line">{igsn.sample_purpose}</span>
        : null;

    const rows: MetadataRow[] = [
        { label: 'Project', value: project || null },
        { label: 'Campaign', value: igsn?.cruise_field_program ?? null },
        { label: 'Type', value: igsn?.sample_type ?? null },
        { label: 'IGSN', value: doi ?? null },
        { label: 'Parent IGSN', value: parentNode },
        { label: 'Purpose', value: purpose },
        { label: 'Release Date', value: releaseDate },
    ];

    const hasContent = rows.some((row) => {
        const value = row.value;
        if (value === null || value === undefined) return false;
        if (typeof value === 'string') return value.trim() !== '';
        return true;
    });

    if (!hasContent) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-general">
            <h2 id="heading-general" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                General
            </h2>
            <MetadataList rows={rows} />
        </LandingPageCard>
    );
}
