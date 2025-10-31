interface Description {
    id: number;
    description: string;
    description_type: string | null;
}

interface AbstractSectionProps {
    descriptions: Description[];
}

/**
 * Abstract Section
 * 
 * Zeigt die Abstract-Description an.
 */
export function AbstractSection({ descriptions }: AbstractSectionProps) {
    // Finde die Abstract-Description (case-insensitive)
    const abstract = descriptions.find(
        (desc) => desc.description_type?.toLowerCase() === 'abstract',
    );

    if (!abstract) {
        return null;
    }

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">Abstract</h3>
            <div className="prose prose-sm max-w-none text-gray-700">
                <p className="mt-0 whitespace-pre-wrap">{abstract.description}</p>
            </div>
        </div>
    );
}
