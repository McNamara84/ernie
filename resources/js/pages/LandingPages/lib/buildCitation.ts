interface Creatorable {
    type: string;
    given_name?: string;
    family_name?: string;
    name?: string;
}

interface Creator {
    creatorable?: Creatorable;
    given_name?: string;
    family_name?: string;
    institution_name?: string;
}

interface Title {
    value: string;
    title_type?: string | null;
}

interface Resource {
    creators?: Creator[];
    titles?: Title[];
    publisher?: string;
    doi?: string;
    publication_year?: number;
    year?: number; // Actual database field name
}

export function buildCitation(resource: Resource): string {
    // Extract creators
    const creators =
        resource.creators
            ?.map((creator) => {
                // Check if creatorable data exists (new structure)
                if (creator.creatorable) {
                    if (creator.creatorable.type === 'Institution') {
                        return creator.creatorable.name;
                    }
                    if (creator.creatorable.type === 'Person') {
                        if (creator.creatorable.family_name && creator.creatorable.given_name) {
                            return `${creator.creatorable.family_name}, ${creator.creatorable.given_name}`;
                        }
                        if (creator.creatorable.family_name) {
                            return creator.creatorable.family_name;
                        }
                    }
                }
                
                // Fallback to old structure (for backward compatibility)
                if (creator.institution_name) {
                    return creator.institution_name;
                }
                if (creator.family_name && creator.given_name) {
                    return `${creator.family_name}, ${creator.given_name}`;
                }
                if (creator.family_name) {
                    return creator.family_name;
                }
                return null;
            })
            .filter(Boolean)
            .join('; ') || 'Unknown Creator';

    // Extract year (check both field names for compatibility)
    const year = resource.year || resource.publication_year || 'n.d.';

    // Extract main title
    const mainTitle =
        resource.titles?.find(
            (t) => !t.title_type || t.title_type === 'MainTitle',
        )?.value || 'Untitled';

    // Extract publisher (default to GFZ Data Services)
    const publisher = resource.publisher || 'GFZ Data Services';

    // Extract DOI
    const doi = resource.doi
        ? `https://doi.org/${resource.doi}`
        : 'DOI not available';

    // Build citation in format: [Creators] ([Year]): [Title]. [Publisher]. [DOI URL]
    return `${creators} (${year}): ${mainTitle}. ${publisher}. ${doi}`;
}
