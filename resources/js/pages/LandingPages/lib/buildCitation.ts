import { abbreviateGivenName } from './abbreviateGivenName';

interface Creatorable {
    type: string;
    given_name?: string | null;
    family_name?: string | null;
    name?: string | null;
}

interface Creator {
    creatorable?: Creatorable;
    given_name?: string;
    family_name?: string;
    institution_name?: string;
}

interface Title {
    title: string;
    /**
     * @deprecated Use 'title' instead. This field is only kept for backward
     * compatibility with legacy data structures and will be removed in a future version.
     */
    value?: string;
    title_type?: string | null;
}

interface Resource {
    creators?: Creator[];
    titles?: Title[];
    publisher?: string;
    doi?: string | null;
    publication_year?: number;
    year?: number; // Actual database field name
}

interface BuildCitationOptions {
    creatorLimit?: number;
}

function formatCreatorName(creator: Creator): string | null {
    // Check if creatorable data exists (new structure)
    if (creator.creatorable) {
        if (creator.creatorable.type === 'Institution') {
            return creator.creatorable.name ?? null;
        }
        if (creator.creatorable.type === 'Person') {
            if (creator.creatorable.family_name && creator.creatorable.given_name) {
                return `${creator.creatorable.family_name}, ${abbreviateGivenName(creator.creatorable.given_name)}`;
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
        return `${creator.family_name}, ${abbreviateGivenName(creator.given_name)}`;
    }
    if (creator.family_name) {
        return creator.family_name;
    }

    return null;
}

function formatCreatorList(creators: Creator[] | undefined, creatorLimit?: number): string {
    const creatorNames = creators?.map(formatCreatorName).filter((name): name is string => Boolean(name)) ?? [];

    if (creatorNames.length === 0) {
        return 'Unknown Creator';
    }

    const limit = typeof creatorLimit === 'number' && Number.isInteger(creatorLimit) && creatorLimit > 0 ? creatorLimit : null;
    const shouldLimit = limit !== null && creatorNames.length > limit;
    const visibleCreatorNames = shouldLimit ? creatorNames.slice(0, limit) : creatorNames;

    return shouldLimit ? `${visibleCreatorNames.join('; ')}; et al.` : visibleCreatorNames.join('; ');
}

export function buildCitation(resource: Resource, options: BuildCitationOptions = {}): string {
    const creators = formatCreatorList(resource.creators, options.creatorLimit);

    // Extract year (check both field names for compatibility)
    const year = resource.year || resource.publication_year || 'n.d.';

    // Extract main title (check both field names for compatibility)
    const mainTitleObj = resource.titles?.find((t) => !t.title_type || t.title_type === 'MainTitle');
    const mainTitle = mainTitleObj?.title || mainTitleObj?.value || 'Untitled';

    // Extract publisher (default to GFZ Data Services)
    const publisher = resource.publisher || 'GFZ Data Services';

    // Extract DOI
    const doi = resource.doi ? `https://doi.org/${resource.doi}` : 'DOI not available';

    // Build citation in format: [Creators] ([Year]): [Title]. [Publisher]. [DOI URL]
    return `${creators} (${year}): ${mainTitle}. ${publisher}. ${doi}`;
}