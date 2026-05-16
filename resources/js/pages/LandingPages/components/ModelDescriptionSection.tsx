import { ExternalLink } from 'lucide-react';

import type { LandingPageRelatedIdentifier } from '@/types/landing-page';

import { resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';
import { LandingPageCard } from './LandingPageCard';

interface ModelDescriptionSectionProps {
    relatedIdentifiers: LandingPageRelatedIdentifier[];
}

/**
 * Model Description Section
 *
 * Displays the IsSupplementTo relation using persisted citation labels.
 */
export function ModelDescriptionSection({ relatedIdentifiers }: ModelDescriptionSectionProps) {
    // Find the IsSupplementTo relation
    const supplementTo = relatedIdentifiers.find((rel) => rel.relation_type === 'IsSupplementTo');

    if (!supplementTo) {
        return null;
    }

    const resolvedUrl = resolveIdentifierUrl(supplementTo.identifier, supplementTo.identifier_type);

    if (!resolvedUrl) {
        return null;
    }

    const displayLabel = supplementTo.citation_label?.trim()
        || supplementTo.related_title?.trim()
        || supplementTo.identifier;

    return (
        <LandingPageCard
            aria-labelledby="heading-model-description"
        >
            <h2 id="heading-model-description" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Model Description</h2>

            <div className="space-y-3">
                <a
                    href={resolvedUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                >
                    <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true" />
                    <span className="flex-1">{displayLabel}</span>
                </a>
            </div>
        </LandingPageCard>
    );
}
