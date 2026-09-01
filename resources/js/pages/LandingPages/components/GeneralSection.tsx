import type { ReactNode } from 'react';

import type { LandingPageIgsnMetadata, LandingPageResourceDate } from '@/types/landing-page';

import { findDateByType, pickDateString } from '../lib/dateHelpers';
import { LandingPageCard } from './LandingPageCard';
import { hasVisibleMetadataRows, MetadataList, type MetadataRow } from './MetadataList';

interface GeneralSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
    dates: LandingPageResourceDate[];
}

/**
 * "General" module for IGSN landing pages — sample-level descriptive metadata.
 *
 * Renders nothing when no field has data so the surrounding layout collapses
 * gracefully on incomplete records.
 */
export function GeneralSection({ igsn, dates }: GeneralSectionProps): ReactNode {
    const releaseDate = pickDateString(findDateByType(dates, 'Available'));

    let parentNode: ReactNode = null;
    if (igsn?.parent?.igsn) {
        parentNode = igsn.parent.landing_page ? (
            <a href={igsn.parent.landing_page.public_url} className="text-gfz-primary underline hover:no-underline dark:text-blue-400">
                {igsn.parent.igsn}
            </a>
        ) : (
            igsn.parent.igsn
        );
    }

    const trimmedPurpose = igsn?.sample_purpose?.trim();
    const purpose = trimmedPurpose ? <span className="whitespace-pre-line">{trimmedPurpose}</span> : null;
    const requests =
        (igsn?.sample_requests ?? [])
            .map((value) => value.trim())
            .filter(Boolean)
            .join('; ') || null;
    const requestedBy =
        (igsn?.sampled_by ?? [])
            .map((value) => value.trim())
            .filter(Boolean)
            .join('; ') || null;

    const rows: MetadataRow[] = [
        { label: 'Project', value: igsn?.user_code ?? null },
        { label: 'Campaign', value: igsn?.cruise_field_program ?? null },
        { label: 'Type', value: igsn?.sample_type ?? null },
        { label: 'Name', value: igsn?.name ?? null },
        { label: 'IGSN', value: igsn?.igsn ?? null },
        { label: 'Parent IGSN', value: parentNode },
        { label: 'Request', value: requests },
        { label: 'Requested by', value: requestedBy },
        { label: 'Purpose', value: purpose },
        { label: 'Release Date', value: releaseDate },
    ];

    const hasContent = hasVisibleMetadataRows(rows);

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
