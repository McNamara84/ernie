import type { LandingPageCreator } from '@/types/landing-page';

import { formatPersonName } from '../lib/formatPersonName';
import { CollapsibleList } from './CollapsibleList';
import { OrcidIcon, RorIcon } from './PidIcons';

interface CreatorsSectionProps {
    creators: LandingPageCreator[];
    displayLimit?: number;
}

/**
 * Renders the list of creators (authors) with ORCID and ROR icons.
 */
export function CreatorsSection({ creators, displayLimit = 50 }: CreatorsSectionProps) {
    if (creators.length === 0) {
        return null;
    }

    return (
        <section className="mt-6" data-testid="creators-section" aria-labelledby="heading-creators">
            <h3 id="heading-creators" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Creators</h3>
            <CollapsibleList
                items={creators}
                threshold={displayLimit}
                itemLabel="creators"
                showSummary={true}
                wrapper={(children) => (
                    <ul className="space-y-2" data-testid="creators-list">
                        {children}
                    </ul>
                )}
                renderItem={(creator) => {
                    const creatorable = creator.creatorable;
                    const firstAffiliation = creator.affiliations[0];
                    const isPerson = creatorable.type === 'Person';
                    const hasOrcid = isPerson && creatorable.name_identifier && creatorable.name_identifier_scheme === 'ORCID';
                    const formattedName = isPerson ? formatPersonName(creatorable.family_name, creatorable.given_name) : creatorable.name;
                    const personName = formattedName === 'Unknown' && creatorable.name ? creatorable.name : formattedName;

                    return (
                        <li key={creator.id} className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                            <span>{personName}</span>

                            {hasOrcid && (
                                <a
                                    href={`https://orcid.org/${creatorable.name_identifier}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="-m-3 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3"
                                    aria-label={`ORCID profile of ${personName}`}
                                >
                                    <OrcidIcon />
                                </a>
                            )}

                            {firstAffiliation && (
                                <>
                                    {(!isPerson || !hasOrcid) && <span>; </span>}
                                    <span>{firstAffiliation.name}</span>

                                    {firstAffiliation.affiliation_identifier && firstAffiliation.affiliation_identifier_scheme === 'ROR' && (
                                        <a
                                            href={firstAffiliation.affiliation_identifier}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="-m-3 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3"
                                            aria-label={`ROR profile of ${firstAffiliation.name}`}
                                        >
                                            <RorIcon />
                                        </a>
                                    )}
                                </>
                            )}
                        </li>
                    );
                }}
            />
        </section>
    );
}
