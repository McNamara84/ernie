import { Fragment } from 'react';

import type { LandingPageAffiliation } from '@/types/landing-page';

import { OrcidIcon, RorIcon } from './PidIcons';

interface PersonMetadataLineProps {
    name: string;
    orcid?: string | null;
    affiliations?: LandingPageAffiliation[];
    roleLabel?: string | null;
}

const PID_ICON_LINK_CLASS =
    '-m-3 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3 align-text-bottom transition-opacity hover:opacity-80';

function resolveOrcidUrl(identifier: string): string {
    const normalized = identifier.trim().replace(/^(?:https?:\/\/)?(?:www\.)?orcid\.org\//i, '');

    return `https://orcid.org/${normalized}`;
}

function isRorAffiliation(affiliation: LandingPageAffiliation): boolean {
    return (
        affiliation.affiliation_identifier !== null &&
        affiliation.affiliation_identifier.trim() !== '' &&
        affiliation.affiliation_identifier_scheme?.toUpperCase() === 'ROR'
    );
}

export function PersonMetadataLine({ name, orcid, affiliations = [], roleLabel }: PersonMetadataLineProps) {
    const normalizedOrcid = orcid?.trim() ?? '';
    const hasOrcid = normalizedOrcid !== '';
    const visibleAffiliations = affiliations.filter((affiliation) => affiliation.name.trim() !== '');

    return (
        <span className="break-words">
            <span>{name}</span>

            {hasOrcid && (
                <>
                    <span aria-hidden="true"> </span>
                    <a
                        href={resolveOrcidUrl(normalizedOrcid)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className={PID_ICON_LINK_CLASS}
                        aria-label={`ORCID profile of ${name}`}
                    >
                        <OrcidIcon />
                    </a>
                </>
            )}

            {visibleAffiliations.map((affiliation, index) => {
                const hasRor = isRorAffiliation(affiliation);

                return (
                    <Fragment key={`${affiliation.id}-${index}`}>
                        <span>; </span>
                        <span>{affiliation.name}</span>
                        {hasRor && (
                            <>
                                <span aria-hidden="true"> </span>
                                <a
                                    href={affiliation.affiliation_identifier ?? undefined}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={PID_ICON_LINK_CLASS}
                                    aria-label={`ROR profile of ${affiliation.name}`}
                                >
                                    <RorIcon />
                                </a>
                            </>
                        )}
                    </Fragment>
                );
            })}

            {roleLabel && <span className="text-gray-500 dark:text-gray-400"> ({roleLabel})</span>}
        </span>
    );
}
