import { ExternalLink } from 'lucide-react';

import type { LandingPageRelatedIdentifier } from '@/types/landing-page';

import { resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';
import { LandingPageCard } from './LandingPageCard';

interface ModelDescriptionSectionProps {
    relatedIdentifiers: LandingPageRelatedIdentifier[];
    resourceType?: string | null;
}

function descriptionHeading(resourceType?: string | null): string {
    switch (resourceType?.trim().toLowerCase()) {
        case 'dataset':
            return 'Dataset Description';
        case 'model':
            return 'Model Description';
        default:
            return 'Model / Method Description';
    }
}

/**
 * Dataset, model, or method description section.
 *
 * Displays every renderable IsSupplementTo relation using persisted citation labels.
 */
export function ModelDescriptionSection({ relatedIdentifiers, resourceType }: ModelDescriptionSectionProps) {
    const supplements = relatedIdentifiers.flatMap((relatedIdentifier) => {
        if (relatedIdentifier.relation_type !== 'IsSupplementTo') {
            return [];
        }

        const resolvedUrl = resolveIdentifierUrl(relatedIdentifier.identifier, relatedIdentifier.identifier_type);

        return resolvedUrl ? [{ relatedIdentifier, resolvedUrl }] : [];
    });

    if (supplements.length === 0) {
        return null;
    }

    const heading = descriptionHeading(resourceType);

    return (
        <LandingPageCard aria-labelledby="heading-model-description">
            <h2 id="heading-model-description" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h2>

            <div className="space-y-3">
                {supplements.map(({ relatedIdentifier, resolvedUrl }) => {
                    const displayLabel =
                        relatedIdentifier.citation_label?.trim() || relatedIdentifier.related_title?.trim() || relatedIdentifier.identifier;

                    return (
                        <a
                            key={relatedIdentifier.id}
                            href={resolvedUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                        >
                            <ExternalLink
                                className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300"
                                aria-hidden="true"
                            />
                            <span className="flex-1">{displayLabel}</span>
                        </a>
                    );
                })}
            </div>
        </LandingPageCard>
    );
}
