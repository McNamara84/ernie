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

function resolveRorUrl(affiliation: LandingPageAffiliation): string | null {
    const normalizedIdentifier = affiliation.affiliation_identifier?.trim() ?? '';

    if (normalizedIdentifier === '' || affiliation.affiliation_identifier_scheme?.toUpperCase() !== 'ROR') {
        return null;
    }

    return normalizedIdentifier;
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
                const rorUrl = resolveRorUrl(affiliation);

                return (
                    <Fragment key={`${affiliation.id}-${index}`}>
                        <span>; </span>
                        <span>{affiliation.name}</span>
                        {rorUrl && (
                            <>
                                <span aria-hidden="true"> </span>
                                <a
                                    href={rorUrl}
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
