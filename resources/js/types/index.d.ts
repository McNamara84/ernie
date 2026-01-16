import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export type FontSize = 'regular' | 'large';

export type UserRole = 'beginner' | 'curator' | 'group_leader' | 'admin';

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
    disabled?: boolean;
    separator?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    fontSizePreference: FontSize;
    resourceCount?: number;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    font_size_preference: FontSize;
    role?: UserRole;
    role_label?: string;
    is_active?: boolean;
    // Gate-based permissions
    can_manage_users?: boolean;
    can_register_production_doi?: boolean;
    can_delete_logs?: boolean;
    // Granular access permissions (Issue #379)
    can_access_logs?: boolean;
    can_access_old_datasets?: boolean;
    can_access_statistics?: boolean;
    can_access_users?: boolean;
    can_access_editor_settings?: boolean;
    // Landing page management permission (Issue #375)
    can_manage_landing_pages?: boolean;
    deactivated_at?: string | null;
    deactivated_by?: {
        id: number;
        name: string;
    } | null;
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

export interface DateType {
    id: number;
    name: string;
    slug: string;
    description: string | null;
}

export interface License {
    id: number;
    identifier: string;
    name: string;
}

/**
 * Right type (DataCite 4.6 - replaces License in database)
 * Frontend continues to use "License" terminology for user familiarity
 */
export interface Right {
    id: number;
    identifier: string;
    name: string;
    uri?: string | null;
}

export interface Language {
    id: number;
    code: string;
    name: string;
}

/**
 * ContributorType (DataCite 4.6 - replaces Role)
 * Standardized contributor types from DataCite schema
 */
export interface ContributorType {
    id: number;
    name: string;
    slug: string;
}

/**
 * @deprecated Use ContributorType instead
 */
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
