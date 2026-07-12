import type { LandingPageContributor } from '@/types/landing-page';

import { formatPersonName } from '../lib/formatPersonName';
import { CollapsibleList } from './CollapsibleList';
import { PersonMetadataLine } from './PersonMetadataLine';

interface ContributorsSectionProps {
    contributors: LandingPageContributor[];
    displayLimit?: number;
}

/**
 * Renders the list of contributors with ORCID/ROR icons and contributor type badges.
 * Collapses when there are more contributors than the configured display limit.
 */
export function ContributorsSection({ contributors, displayLimit = 50 }: ContributorsSectionProps) {
    if (contributors.length === 0) {
        return null;
    }

    return (
        <section className="mt-6" data-testid="contributors-section" aria-labelledby="heading-contributors">
            <h3 id="heading-contributors" className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Contributors
            </h3>
            <CollapsibleList
                items={contributors}
                threshold={displayLimit}
                itemLabel="contributors"
                showSummary={true}
                wrapper={(children) => (
                    <ul className="space-y-2" data-testid="contributors-list">
                        {children}
                    </ul>
                )}
                renderItem={(contributor) => {
                    const contributorable = contributor.contributorable;
                    const isPerson = contributorable.type === 'Person';
                    const hasOrcid = isPerson && contributorable.name_identifier && contributorable.name_identifier_scheme === 'ORCID';
                    const formattedName = isPerson ? formatPersonName(contributorable.family_name, contributorable.given_name) : contributorable.name;
                    const personName = (formattedName === 'Unknown' && contributorable.name ? contributorable.name : formattedName) ?? 'Unknown';
                    const roleLabel = contributor.contributor_types.length > 0 ? contributor.contributor_types.join(', ') : null;

                    return (
                        <li key={contributor.id} className="text-sm leading-6 text-gray-700 dark:text-gray-300">
                            <PersonMetadataLine
                                name={personName}
                                orcid={hasOrcid ? contributorable.name_identifier : null}
                                affiliations={contributor.affiliations}
                                roleLabel={roleLabel}
                            />
                        </li>
                    );
                }}
            />
        </section>
    );
}
