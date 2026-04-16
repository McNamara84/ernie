import type { LandingPageContributor } from '@/types/landing-page';

import { formatPersonName } from '../lib/formatPersonName';
import { CollapsibleList } from './CollapsibleList';
import { OrcidIcon, RorIcon } from './PidIcons';

interface ContributorsSectionProps {
    contributors: LandingPageContributor[];
}

/**
 * Renders the list of contributors with ORCID/ROR icons and contributor type badges.
 * Collapses when there are more than 10 contributors.
 */
export function ContributorsSection({ contributors }: ContributorsSectionProps) {
    if (contributors.length === 0) {
        return null;
    }

    return (
        <section className="mt-6" data-testid="contributors-section" aria-labelledby="heading-contributors">
            <h2 id="heading-contributors" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Contributors</h2>
            <CollapsibleList
                items={contributors}
                itemLabel="contributors"
                wrapper={(children) => (
                    <ul className="space-y-2" data-testid="contributors-list">
                        {children}
                    </ul>
                )}
                renderItem={(contributor) => {
                    const contributorable = contributor.contributorable;
                    const firstAffiliation = contributor.affiliations[0];
                    const isPerson = contributorable.type === 'Person';
                    const hasOrcid = isPerson && contributorable.name_identifier && contributorable.name_identifier_scheme === 'ORCID';
                    const formattedName = isPerson ? formatPersonName(contributorable.family_name, contributorable.given_name) : contributorable.name;
                    const personName = formattedName === 'Unknown' && contributorable.name ? contributorable.name : formattedName;

                    return (
                        <li key={contributor.id} className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                            <span>{personName}</span>

                            {hasOrcid && (
                                <a
                                    href={`https://orcid.org/${contributorable.name_identifier}`}
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

                            {contributor.contributor_types.length > 0 && (
                                <span className="text-gray-500 dark:text-gray-400">({contributor.contributor_types.join(', ')})</span>
                            )}
                        </li>
                    );
                }}
            />
        </section>
    );
}
