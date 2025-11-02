interface Authorable {
    type: string;
    first_name?: string;
    last_name?: string;
    name?: string;
}

interface Author {
    authorable?: Authorable;
    first_name?: string;
    last_name?: string;
    institution_name?: string;
}

interface Title {
    title: string;
    title_type?: string | null;
}

interface Resource {
    authors?: Author[];
    titles?: Title[];
    publisher?: string;
    doi?: string;
    publication_year?: number;
    year?: number; // Actual database field name
}

export function buildCitation(resource: Resource): string {
    // Extract authors
    const authors =
        resource.authors
            ?.map((author) => {
                // Check if authorable data exists (new structure)
                if (author.authorable) {
                    if (author.authorable.type === 'Institution') {
                        return author.authorable.name;
                    }
                    if (author.authorable.type === 'Person') {
                        if (author.authorable.last_name && author.authorable.first_name) {
                            return `${author.authorable.last_name}, ${author.authorable.first_name}`;
                        }
                        if (author.authorable.last_name) {
                            return author.authorable.last_name;
                        }
                    }
                }
                
                // Fallback to old structure (for backward compatibility)
                if (author.institution_name) {
                    return author.institution_name;
                }
                if (author.last_name && author.first_name) {
                    return `${author.last_name}, ${author.first_name}`;
                }
                if (author.last_name) {
                    return author.last_name;
                }
                return null;
            })
            .filter(Boolean)
            .join('; ') || 'Unknown Author';

    // Extract year (check both field names for compatibility)
    const year = resource.year || resource.publication_year || 'n.d.';

    // Extract main title
    const mainTitle =
        resource.titles?.find(
            (t) => !t.title_type || t.title_type === 'MainTitle',
        )?.title || 'Untitled';

    // Extract publisher (default to GFZ Data Services)
    const publisher = resource.publisher || 'GFZ Data Services';

    // Extract DOI
    const doi = resource.doi
        ? `https://doi.org/${resource.doi}`
        : 'DOI not available';

    // Build citation in format: [Authors] ([Year]): [Title]. [Publisher]. [DOI URL]
    return `${authors} (${year}): ${mainTitle}. ${publisher}. ${doi}`;
}
