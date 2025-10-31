import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RelatedIdentifier {
    id: number;
    identifier: string;
    identifier_type: string;
    relation_type: string;
    related_title?: string;
}

interface RelatedWorkSectionProps {
    relatedIdentifiers: RelatedIdentifier[];
}

interface Citation {
    doi: string;
    citation: string;
    loading: boolean;
    error: boolean;
    relatedTitle?: string;
}

/**
 * Wandelt CamelCase in lesbaren Text mit Leerzeichen um
 * z.B. "IsDocumentedBy" -> "Is Documented By"
 */
function formatRelationType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

/**
 * Related Work Section
 * 
 * Zeigt alle Related Identifiers gruppiert nach RelationType an.
 * Erste IsSupplementTo-Relation wird ausgeschlossen (die ist in Model Description).
 */
export function RelatedWorkSection({
    relatedIdentifiers,
}: RelatedWorkSectionProps) {
    const [citations, setCitations] = useState<Map<string, Citation>>(new Map());

    // Erste IsSupplementTo-Relation ausschließen
    const firstSupplementToIndex = relatedIdentifiers.findIndex(
        (rel) => rel.relation_type === 'IsSupplementTo',
    );

    const filteredRelations = relatedIdentifiers.filter((rel, index) => {
        // Erste IsSupplementTo ausschließen
        if (rel.relation_type === 'IsSupplementTo' && index === firstSupplementToIndex) {
            return false;
        }
        return true;
    });

    // Nach RelationType gruppieren
    const groupedByType = filteredRelations.reduce((acc, rel) => {
        if (!acc[rel.relation_type]) {
            acc[rel.relation_type] = [];
        }
        acc[rel.relation_type].push(rel);
        return acc;
    }, {} as Record<string, RelatedIdentifier[]>);

    // Sortiere die Gruppen alphabetisch
    const sortedTypes = Object.keys(groupedByType).sort();

    useEffect(() => {
        // Lade Citations für alle DOIs
        filteredRelations.forEach((rel) => {
            if (rel.identifier_type !== 'DOI') {
                return;
            }

            const key = `${rel.id}`;
            
            // Initialisiere mit loading state
            setCitations((prev) => new Map(prev).set(key, {
                doi: rel.identifier,
                citation: '',
                loading: true,
                error: false,
                relatedTitle: rel.related_title,
            }));

            // Lade Citation asynchron
            fetch(`/api/datacite/citation/${encodeURIComponent(rel.identifier)}`)
                .then((response) => {
                    if (response.ok) {
                        return response.json();
                    }
                    throw new Error('Citation not found');
                })
                .then((data) => {
                    setCitations((prev) => new Map(prev).set(key, {
                        doi: rel.identifier,
                        citation: data.citation,
                        loading: false,
                        error: false,
                        relatedTitle: rel.related_title,
                    }));
                })
                .catch(() => {
                    setCitations((prev) => new Map(prev).set(key, {
                        doi: rel.identifier,
                        citation: '',
                        loading: false,
                        error: true,
                        relatedTitle: rel.related_title,
                    }));
                });
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [relatedIdentifiers]);

    if (filteredRelations.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="mb-4 text-lg font-semibold text-gray-900">
                Related Work
            </h3>

            <div className="space-y-6">
                {sortedTypes.map((relationType) => (
                    <div key={relationType}>
                        <h4 className="mb-3 text-sm font-semibold text-gray-700">
                            {formatRelationType(relationType)}
                        </h4>
                        <ul className="space-y-2">
                            {groupedByType[relationType].map((rel) => {
                                const key = `${rel.id}`;
                                const citationData = citations.get(key);

                                // Nur DOIs anzeigen
                                if (rel.identifier_type !== 'DOI') {
                                    return null;
                                }

                                return (
                                    <li key={rel.id}>
                                        {citationData?.loading && (
                                            <div className="flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-500">
                                                <div className="mt-0.5 h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-gray-300 border-t-gray-600" />
                                                <span>Loading citation...</span>
                                            </div>
                                        )}

                                        {!citationData?.loading && citationData?.citation && (
                                            <a
                                                href={`https://doi.org/${rel.identifier}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                                            >
                                                <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                                                <span className="flex-1">{citationData.citation}</span>
                                            </a>
                                        )}

                                        {!citationData?.loading && citationData?.error && citationData?.relatedTitle && (
                                            <a
                                                href={`https://doi.org/${rel.identifier}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                                            >
                                                <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                                                <span className="flex-1">{citationData.relatedTitle}</span>
                                            </a>
                                        )}

                                        {!citationData?.loading && citationData?.error && !citationData?.relatedTitle && (
                                            <a
                                                href={`https://doi.org/${rel.identifier}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                                            >
                                                <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                                                <span className="flex-1">DOI: {rel.identifier}</span>
                                            </a>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}
