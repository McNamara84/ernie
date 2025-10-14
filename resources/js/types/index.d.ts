import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    resourceCount?: number;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export interface ResourceType {
    id: number;
    name: string;
}

export interface TitleType {
    id: number;
    name: string;
    slug: string;
}

export interface License {
    id: number;
    identifier: string;
    name: string;
}

export interface Language {
    id: number;
    code: string;
    name: string;
}

export interface Role {
    id: number;
    name: string;
    slug: string;
}

export interface RelatedIdentifier {
    id?: number;
    identifier: string;
    identifier_type: string;
    relation_type: string;
    position?: number;
    related_title?: string | null;
    related_metadata?: Record<string, unknown> | null;
}

export interface RelatedIdentifierFormData {
    identifier: string;
    identifierType: string;
    relationType: string;
}

export type IdentifierType = 
    | 'DOI'
    | 'URL'
    | 'Handle'
    | 'IGSN'
    | 'URN'
    | 'ISBN'
    | 'ISSN'
    | 'PURL'
    | 'ARK'
    | 'arXiv'
    | 'bibcode'
    | 'EAN13'
    | 'EISSN'
    | 'ISTC'
    | 'LISSN'
    | 'LSID'
    | 'PMID'
    | 'UPC'
    | 'w3id';

export type RelationType =
    // Citation
    | 'Cites'
    | 'IsCitedBy'
    | 'References'
    | 'IsReferencedBy'
    // Documentation
    | 'Documents'
    | 'IsDocumentedBy'
    | 'Describes'
    | 'IsDescribedBy'
    // Versions
    | 'IsNewVersionOf'
    | 'IsPreviousVersionOf'
    | 'HasVersion'
    | 'IsVersionOf'
    | 'Continues'
    | 'IsContinuedBy'
    | 'Obsoletes'
    | 'IsObsoletedBy'
    | 'IsVariantFormOf'
    | 'IsOriginalFormOf'
    | 'IsIdenticalTo'
    // Compilation
    | 'HasPart'
    | 'IsPartOf'
    | 'Compiles'
    | 'IsCompiledBy'
    // Derivation
    | 'IsSourceOf'
    | 'IsDerivedFrom'
    // Supplement
    | 'IsSupplementTo'
    | 'IsSupplementedBy'
    // Software
    | 'Requires'
    | 'IsRequiredBy'
    // Metadata
    | 'HasMetadata'
    | 'IsMetadataFor'
    // Reviews
    | 'Reviews'
    | 'IsReviewedBy'
    // Other
    | 'IsPublishedIn'
    | 'Collects'
    | 'IsCollectedBy';

export interface MSLLaboratory {
    identifier: string;
    name: string;
    affiliation_name: string;
    affiliation_ror: string;
}
