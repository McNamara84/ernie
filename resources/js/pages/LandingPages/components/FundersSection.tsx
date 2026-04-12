import type { LandingPageFundingReference } from '@/types/landing-page';

import { CollapsibleList } from './CollapsibleList';
import { CrossrefFunderIcon, RorIcon } from './PidIcons';

interface FundersSectionProps {
    fundingReferences: LandingPageFundingReference[];
}

/**
 * Renders the list of funding references with ROR and Crossref Funder icons.
 * Collapses when there are more than 10 funders.
 */
export function FundersSection({ fundingReferences }: FundersSectionProps) {
    if (fundingReferences.length === 0) {
        return null;
    }

    return (
        <div className="mt-6" data-testid="funding-section">
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Funders</h3>
            <CollapsibleList itemCount={fundingReferences.length} itemLabel="funders">
                <ul className="space-y-2" data-testid="funding-list">
                    {fundingReferences.map((funding) => (
                        <li key={funding.id} className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-300">
                            <span>{funding.funder_name}</span>

                            {funding.funder_identifier_type === 'ROR' && funding.funder_identifier && (
                                <a
                                    href={funding.funder_identifier}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="-m-3 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3"
                                    aria-label={`ROR profile of ${funding.funder_name}`}
                                >
                                    <RorIcon />
                                </a>
                            )}

                            {funding.funder_identifier_type === 'Crossref Funder ID' && funding.funder_identifier && (
                                <a
                                    href={`https://doi.org/${funding.funder_identifier}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="-m-3 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3"
                                    aria-label={`Crossref Funder ID for ${funding.funder_name}`}
                                >
                                    <CrossrefFunderIcon />
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            </CollapsibleList>
        </div>
    );
}
