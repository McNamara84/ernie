/**
 * Shared type definitions for DataCite Related Items (Citation Manager).
 *
 * Mirrors the backend models under `App\Models\RelatedItem*`
 * and the DataCite 4.7 `relatedItem` schema.
 */

export type RelatedItemNameType = 'Personal' | 'Organizational';

export interface RelatedItemAffiliation {
    name: string;
    affiliation_identifier?: string | null;
    scheme?: string | null;
    scheme_uri?: string | null;
}

export interface RelatedItemCreator {
    id?: number;
    name: string;
    name_type: RelatedItemNameType;
    given_name?: string | null;
    family_name?: string | null;
    name_identifier?: string | null;
    name_identifier_scheme?: string | null;
    scheme_uri?: string | null;
    position: number;
    affiliations: RelatedItemAffiliation[];
}

export interface RelatedItemContributor extends RelatedItemCreator {
    contributor_type: string;
}

export interface RelatedItemTitle {
    id?: number;
    title: string;
    title_type: 'MainTitle' | 'Subtitle' | 'TranslatedTitle' | 'AlternativeTitle';
    position: number;
}

export interface RelatedItem {
    id?: number;
    resource_id?: number;
    related_item_type: string;
    relation_type_id: number;
    relation_type_slug?: string;
    publication_year?: number | null;
    volume?: string | null;
    issue?: string | null;
    number?: string | null;
    number_type?: string | null;
    first_page?: string | null;
    last_page?: string | null;
    publisher?: string | null;
    edition?: string | null;
    identifier?: string | null;
    identifier_type?: string | null;
    related_metadata_scheme?: string | null;
    scheme_uri?: string | null;
    scheme_type?: string | null;
    position: number;
    titles: RelatedItemTitle[];
    creators: RelatedItemCreator[];
    contributors: RelatedItemContributor[];
}

/**
 * Shape returned by `GET /api/v1/citation-lookup`.
 */
export interface CitationLookupResult {
    source: 'crossref' | 'datacite' | 'not_found';
    identifier: string;
    identifier_type: string;
    related_item_type?: string | null;
    title?: string | null;
    subtitle?: string | null;
    publication_year?: number | null;
    publisher?: string | null;
    volume?: string | null;
    issue?: string | null;
    first_page?: string | null;
    last_page?: string | null;
    creators?: Array<{
        name: string;
        name_type: RelatedItemNameType;
        given_name?: string | null;
        family_name?: string | null;
        name_identifier?: string | null;
        name_identifier_scheme?: string | null;
    }>;
}
