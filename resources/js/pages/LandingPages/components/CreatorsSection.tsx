import type { LandingPageCreator } from '@/types/landing-page';

import { formatPersonName } from '../lib/formatPersonName';
import { CollapsibleList } from './CollapsibleList';
import { PersonMetadataLine } from './PersonMetadataLine';

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
            <h3 id="heading-creators" className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Creators
            </h3>
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
                    const isPerson = creatorable.type === 'Person';
                    const hasOrcid = isPerson && creatorable.name_identifier && creatorable.name_identifier_scheme === 'ORCID';
                    const formattedName = isPerson ? formatPersonName(creatorable.family_name, creatorable.given_name) : creatorable.name;
                    const personName = (formattedName === 'Unknown' && creatorable.name ? creatorable.name : formattedName) ?? 'Unknown';

                    return (
                        <li key={creator.id} className="text-sm leading-6 text-gray-700 dark:text-gray-300">
                            <PersonMetadataLine
                                name={personName}
                                orcid={hasOrcid ? creatorable.name_identifier : null}
                                affiliations={creator.affiliations}
                            />
                        </li>
                    );
                }}
            />
        </section>
    );
}
