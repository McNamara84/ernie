import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Skeleton } from '@/components/ui/skeleton';

import { useFadeInOnScroll } from '../hooks/useFadeInOnScroll';
import { resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';

interface RelatedIdentifier {
    id: number;
    identifier: string;
    identifier_type: string;
    relation_type: string;
    related_title?: string;
}

interface ModelDescriptionSectionProps {
    relatedIdentifiers: RelatedIdentifier[];
}

/**
 * Model Description Section
 *
 * Zeigt die IsSupplementTo-Relation mit Zitation von der DOI an.
 */
export function ModelDescriptionSection({ relatedIdentifiers }: ModelDescriptionSectionProps) {
    const [citation, setCitation] = useState<string | null>(null);
    const [doi, setDoi] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const { ref, isVisible } = useFadeInOnScroll();

    // Find the IsSupplementTo relation
    const supplementTo = relatedIdentifiers.find((rel) => rel.relation_type === 'IsSupplementTo');

    useEffect(() => {
        if (!supplementTo || supplementTo.identifier_type !== 'DOI' || !supplementTo.identifier.trim()) {
            // Reset state when switching from a valid DOI to a non-DOI/empty value
            setCitation(null);
            setDoi(null);
            setLoading(false);
            return;
        }

        const controller = new AbortController();

        const fetchCitation = async () => {
            setLoading(true);
            try {
                const url = `/api/datacite/citation/${encodeURIComponent(supplementTo.identifier)}`;
                const response = await fetch(url, { signal: controller.signal });

                if (response.ok) {
                    const data = await response.json();
                    setCitation(data.citation);
                    setDoi(supplementTo.identifier);
                }
            } catch (err: unknown) {
                if (err instanceof Error && err.name === 'AbortError') {
                    return;
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        };

        fetchCitation();

        return () => {
            controller.abort();
            // Ensure loading is cleared so the next effect run won't find stuck state
            setLoading(false);
        };
    }, [supplementTo]);

    if (!supplementTo) {
        return null;
    }

    const resolvedUrl = resolveIdentifierUrl(supplementTo.identifier, supplementTo.identifier_type);

    if (!resolvedUrl) {
        return null;
    }

    return (
        <section
            ref={ref}
            aria-labelledby="heading-model-description"
            className={`rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-all duration-200 ease-in-out hover:shadow-md dark:border-gray-700 dark:bg-gray-800 ${isVisible ? 'opacity-100' : 'opacity-0'}`}
        >
            <h2 id="heading-model-description" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">Model Description</h2>

            <div className="space-y-3">
                {loading && (
                    <div className="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700" aria-busy="true">
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-4 w-1/2" />
                    </div>
                )}

                {!loading && citation && doi && resolvedUrl && (
                    <a
                        href={resolvedUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                    >
                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true" />
                        <span className="flex-1">{citation}</span>
                    </a>
                )}

                {!loading && !citation && resolvedUrl && (
                    <a
                        href={resolvedUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700/50"
                    >
                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true" />
                        <span className="flex-1">
                            {supplementTo.related_title || supplementTo.identifier}
                        </span>
                    </a>
                )}
            </div>
        </section>
    );
}
