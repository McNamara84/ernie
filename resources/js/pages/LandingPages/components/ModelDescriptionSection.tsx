import { ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';

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

export function ModelDescriptionSection({
    relatedIdentifiers,
}: ModelDescriptionSectionProps) {
    const [citation, setCitation] = useState<string | null>(null);
    const [doi, setDoi] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    // Find the oldest "IsSupplementTo" relation
    const supplementTo = relatedIdentifiers.find(
        (rel) => rel.relation_type === 'IsSupplementTo',
    );

    useEffect(() => {
        if (!supplementTo || supplementTo.identifier_type !== 'DOI') {
            return;
        }

        const fetchCitation = async () => {
            setLoading(true);
            try {
                const response = await fetch(
                    `/api/datacite/citation/${encodeURIComponent(supplementTo.identifier)}`,
                );

                if (response.ok) {
                    const data = await response.json();
                    setCitation(data.citation);
                    setDoi(supplementTo.identifier);
                }
            } catch (error) {
                console.error('Failed to fetch DataCite citation:', error);
            } finally {
                setLoading(false);
            }
        };

        fetchCitation();
    }, [supplementTo]);

    if (!supplementTo) {
        return null;
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="mb-4 text-lg font-semibold text-gray-900">
                Model Description
            </h3>

            <div className="space-y-3">
                {loading && (
                    <p className="text-sm text-gray-500">Loading citation...</p>
                )}

                {!loading && citation && doi && (
                    <a
                        href={`https://doi.org/${doi}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                    >
                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                        <span className="flex-1">{citation}</span>
                    </a>
                )}

                {!loading && !citation && supplementTo.related_title && (
                    <a
                        href={`https://doi.org/${supplementTo.identifier}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition-colors hover:border-gray-300 hover:bg-gray-50"
                    >
                        <ExternalLink className="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600" />
                        <span className="flex-1">{supplementTo.related_title}</span>
                    </a>
                )}
            </div>
        </div>
    );
}
