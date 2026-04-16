import type { LandingPageFundingReference } from '@/types/landing-page';

import { CollapsibleList } from './CollapsibleList';
import { CrossrefFunderIcon, RorIcon } from './PidIcons';

/**
 * Resolves a Crossref Funder identifier to a full URL.
 * If the value is already an http(s) URL, it is returned as-is.
 * Otherwise it is treated as a bare DOI and prefixed with the doi.org resolver.
 */
function resolveCrossrefFunderUrl(identifier: string): string {
    if (/^https?:\/\//i.test(identifier)) {
        return identifier;
    }
    return `https://doi.org/${identifier}`;
}

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
        <section className="mt-6" data-testid="funding-section" aria-labelledby="heading-funders">
            <h3 id="heading-funders" className="text-lg font-semibold text-gray-900 dark:text-gray-100">Funders</h3>
            <CollapsibleList
                items={fundingReferences}
                itemLabel="funders"
                wrapper={(children) => (
                    <ul className="space-y-2" data-testid="funding-list">
                        {children}
                    </ul>
                )}
                renderItem={(funding) => (
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
                                href={resolveCrossrefFunderUrl(funding.funder_identifier)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="-m-3 inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center p-3"
                                aria-label={`Crossref Funder ID for ${funding.funder_name}`}
                            >
                                <CrossrefFunderIcon />
                            </a>
                        )}
                    </li>
                )}
            />
        </section>
    );
}
