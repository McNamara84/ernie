/**
 * Right column section identifiers for landing page templates.
 */
export type RightColumnSection = 'descriptions' | 'creators' | 'contributors' | 'funders' | 'keywords' | 'metadata_download' | 'location';

/**
 * Left column section identifiers for landing page templates.
 */
export type LeftColumnSection = 'files' | 'contact' | 'model_description' | 'related_work';

/**
 * Section order configuration for landing page templates.
 */
export interface SectionOrder {
    rightColumn: RightColumnSection[];
    leftColumn: LeftColumnSection[];
}

/**
 * Custom Landing Page Template
 *
 * Represents a user-created template configuration (cloned from default).
 * Templates define section order and optional custom logo.
 */
export interface LandingPageTemplateConfig {
    /** Primary key */
    id: number;

    /** Template display name */
    name: string;

    /** URL-safe slug (unique) */
    slug: string;

    /** Whether this is the immutable default template */
    is_default: boolean;

    /** Storage path for custom logo */
    logo_path: string | null;

    /** Original filename of uploaded logo */
    logo_filename: string | null;

    /** Computed URL to logo image */
    logo_url: string | null;

    /** Ordered right column sections */
    right_column_order: RightColumnSection[];

    /** Ordered left column sections */
    left_column_order: LeftColumnSection[];

    /** ID of user who created this template */
    created_by: number | null;

    /** Creator user object (when loaded) */
    creator?: { id: number; name: string } | null;

    /** Number of landing pages using this template */
    landing_pages_count?: number;

    /** Timestamps */
    created_at: string;
    updated_at: string;
}

/**
 * Lightweight template summary returned by `/api/landing-page-templates`.
 *
 * Only the fields the API actually returns – used in the SetupLandingPageModal dropdown.
 */
export interface LandingPageTemplateSummary {
    id: number;
    name: string;
    slug: string;
    is_default: boolean;
    logo_path: string | null;
    logo_url: string | null;
    right_column_order: RightColumnSection[];
    left_column_order: LeftColumnSection[];
}

/**
 * Landing Page Domain
 *
 * Represents a domain entry for external landing page URLs.
 * Managed by admins/group leaders on the /settings page.
 */
export interface LandingPageDomain {
    /** Primary key */
    id: number;

    /** Full domain URL including protocol and trailing slash (e.g., "https://geofon.gfz.de/") */
    domain: string;
}

/**
 * Landing Page File
 *
 * Represents a download file entry from the landing_page_files table.
 * Files are imported from the legacy metaworks database during DataCite import.
 */
export interface LandingPageFile {
    /** Primary key */
    id: number;

    /** Download URL */
    url: string;

    /** Display order */
    position: number;
}

/**
 * Landing Page Link
 *
 * Represents an additional download link added by curators.
 * Displayed below the primary download link on the public landing page.
 */
export interface LandingPageLink {
    /** Primary key (undefined for new unsaved links) */
    id?: number;

    /** Stable client-side identifier for new unsaved links (not sent to server) */
    _clientId?: string;

    /** Link URL */
    url: string;

    /** Curator-defined display text */
    label: string;

    /** Display order (drag-and-drop) */
    position: number;
}

/**
 * Landing Page Configuration Type
 *
 * Represents the database model for landing_pages table
 */
export interface LandingPageConfig {
    /** Primary key */
    id: number;

    /** Foreign key to resources table */
    resource_id: number;

    /** Template identifier (e.g., 'default_gfz') */
    template: string;

    /** FK to landing_page_templates table (null = use built-in default) */
    landing_page_template_id?: number | null;

    /** FTP URL for dataset downloads (optional) */
    ftp_url?: string | null;

    /** FK to landing_page_domains (only for external template) */
    external_domain_id?: number | null;

    /** URL path appended to domain (only for external template) */
    external_path?: string | null;

    /** Computed: composed external URL (domain + path), null for internal pages */
    external_url?: string | null;

    /** The external domain object (loaded via relationship) */
    external_domain?: LandingPageDomain | null;

    /** Computed: contact form URL for data requests (internal route, not configurable) */
    contact_url?: string;

    /** Publication status: draft (preview-only) or published (public) */
    status: 'draft' | 'published';

    /** Preview token for draft mode (64 characters) */
    preview_token?: string | null;

    /** Timestamp when landing page was published */
    published_at?: string | null;

    /** View counter for analytics */
    view_count: number;

    /** Last time the landing page was viewed */
    last_viewed_at?: string | null;

    /** Creation timestamp */
    created_at: string;

    /** Last update timestamp */
    updated_at: string;

    /** Computed: public URL for landing page */
    public_url: string;

    /** Computed: preview URL with token */
    preview_url: string;

    /** Download file entries from landing_page_files table */
    files?: LandingPageFile[];

    /** Additional download links added by curators */
    links?: LandingPageLink[];
}

/**
 * Affiliation data for creators/contributors on landing pages
 */
export interface LandingPageAffiliation {
    id: number;
    name: string;
    affiliation_identifier: string | null;
    affiliation_identifier_scheme: string | null;
}

/**
 * Creatorable entity (Person or Institution)
 */
export interface LandingPageCreatorable {
    type: string;
    id: number;
    /** Person: given name */
    given_name: string | null;
    /** Person: family name */
    family_name: string | null;
    /** Person: ORCID or other identifier */
    name_identifier: string | null;
    name_identifier_scheme: string | null;
    /** Institution: organization name */
    name: string | null;
}

/**
 * Creator entry for landing pages
 */
export interface LandingPageCreator {
    id: number;
    position: number;
    is_contact_person?: boolean;
    affiliations: LandingPageAffiliation[];
    creatorable: LandingPageCreatorable;
}

/**
 * Contributor entry for landing pages
 */
export interface LandingPageContributor {
    id: number;
    position: number;
    contributor_types: string[];
    affiliations: LandingPageAffiliation[];
    contributorable: LandingPageCreatorable;
}

/**
 * Title entry for landing pages
 */
export interface LandingPageTitle {
    id: number;
    title: string;
    title_type: string | null;
    language?: string | null;
}

/**
 * Description entry for landing pages
 */
export interface LandingPageDescription {
    id: number;
    value: string;
    description_type: string | null;
}

/**
 * License entry for landing pages
 */
export interface LandingPageLicense {
    id: number;
    /** Display name of the license */
    name: string;
    /** SPDX identifier (e.g., 'CC-BY-4.0') */
    spdx_id: string;
    /** URL to license text */
    reference: string;
    /** Legacy: license name */
    rights?: string;
    /** Legacy: license URI */
    rights_uri?: string | null;
    /** Legacy: license identifier */
    rights_identifier?: string | null;
}

/**
 * Related identifier entry for landing pages
 */
export interface LandingPageRelatedIdentifier {
    id: number;
    /** The identifier value (e.g., DOI URL) */
    identifier: string;
    /** Identifier type (e.g., 'DOI', 'URL') */
    identifier_type: string;
    /** Relation type (e.g., 'IsSupplementTo', 'References') */
    relation_type: string;
    /** General resource type (e.g., 'Dataset', 'Text') */
    resource_type_general?: string | null;
    /** Legacy: identifier value */
    value?: string;
    /** Legacy: related identifier type */
    related_identifier_type?: string;
    /** Related resource title (if available) */
    related_title?: string | null;
}

/**
 * Funding reference entry for landing pages
 */
export interface LandingPageFundingReference {
    id: number;
    funder_name: string;
    funder_identifier: string | null;
    funder_identifier_type: string | null;
    award_number: string | null;
    award_uri: string | null;
    award_title: string | null;
    position: number;
}

/**
 * Subject/keyword entry for landing pages
 */
export interface LandingPageSubject {
    id: number;
    subject: string;
    subject_scheme: string | null;
    scheme_uri: string | null;
    value_uri: string | null;
    classification_code: string | null;
}

/**
 * Geo location point for landing pages
 */
export interface LandingPageGeoLocationPoint {
    point_latitude: number;
    point_longitude: number;
}

/**
 * Geo location box for landing pages
 */
export interface LandingPageGeoLocationBox {
    west_bound_longitude: number;
    east_bound_longitude: number;
    south_bound_latitude: number;
    north_bound_latitude: number;
}

/**
 * Geo location entry for landing pages
 */
export interface LandingPageGeoLocation {
    id: number;
    /** Place name description */
    place: string | null;
    /** Legacy: place name */
    geo_location_place?: string | null;
    /** Point: longitude coordinate */
    point_longitude: number | null;
    /** Point: latitude coordinate */
    point_latitude: number | null;
    /** Legacy: point as nested object */
    geo_location_point?: LandingPageGeoLocationPoint | null;
    /** Box: western boundary */
    west_bound_longitude: number | null;
    /** Box: eastern boundary */
    east_bound_longitude: number | null;
    /** Box: southern boundary */
    south_bound_latitude: number | null;
    /** Box: northern boundary */
    north_bound_latitude: number | null;
    /** Legacy: box as nested object */
    geo_location_box?: LandingPageGeoLocationBox | null;
    /** Polygon points for complex boundaries */
    polygon_points: Array<{ longitude: number; latitude: number }> | null;
    /** Explicit geo-location type: point, box, polygon, line */
    geo_type: string | null;
}

/**
 * Resource type for landing pages
 */
export interface LandingPageResourceType {
    id: number;
    name: string;
    type_general?: string;
}

/**
 * Contact person entry for landing pages.
 * Derived from creators with is_contact=true and contributors with ContributorType "ContactPerson".
 */
export interface LandingPageContactPerson {
    id: number;
    /** Full name */
    name: string;
    /** Given (first) name */
    given_name: string | null;
    /** Family (last) name */
    family_name: string | null;
    /** Person type (e.g., 'Person' or 'Institution') */
    type: string;
    /** Source: 'creator' or 'contributor' — indicates which model the contact person comes from */
    source: 'creator' | 'contributor';
    /** Affiliations */
    affiliations: Array<{
        name: string;
        identifier: string | null;
        scheme: string | null;
    }>;
    /** ORCID identifier */
    orcid: string | null;
    /** Personal/institutional website */
    website: string | null;
    /** Whether email is available (for contact form) */
    has_email: boolean;
    /** Email address (only when exposed by backend) */
    email?: string;
}

/**
 * Complete resource data as passed to landing page templates
 *
 * Arrays are optional to support partial data from backend
 */
export interface LandingPageResource {
    id: number;
    identifier: string | null;
    publication_year?: number;
    version: string | null;
    language: string | null;
    resource_type?: LandingPageResourceType | null;
    titles?: LandingPageTitle[];
    descriptions?: LandingPageDescription[];
    creators?: LandingPageCreator[];
    contributors?: LandingPageContributor[];
    licenses?: LandingPageLicense[];
    related_identifiers?: LandingPageRelatedIdentifier[];
    funding_references?: LandingPageFundingReference[];
    subjects?: LandingPageSubject[];
    geo_locations?: LandingPageGeoLocation[];
    contact_persons?: LandingPageContactPerson[];
}

/**
 * Props passed to landing page templates via Inertia
 */
export interface LandingPageTemplateProps {
    /** The resource data to display */
    resource: LandingPageResource;
    /** Landing page configuration */
    landingPage: LandingPageConfig;
    /** Whether this is a preview (draft mode) */
    isPreview: boolean;
    /** Custom section order from template (null = use default) */
    sectionOrder?: SectionOrder | null;
    /** Custom logo URL from template (null = use default GFZ logo) */
    customLogoUrl?: string | null;
}

/**
 * Template Metadata
 *
 * Describes available landing page templates
 */
export interface TemplateMetadata {
    /** Unique template identifier (matches LandingPageConfig.template) */
    key: string;

    /** Human-readable template name */
    name: string;

    /** Brief description of template design/features */
    description: string;

    /** URL to template preview image (optional) */
    previewImage?: string;

    /** Template category (for future organization) */
    category?: 'official' | 'custom' | 'experimental';

    /** Template version (for migration tracking) */
    version?: string;

    /** Restrict template to specific resource types. null/undefined = all types allowed */
    resourceTypes?: string[] | null;
}

/**
 * Template Option for Select Dropdown
 */
export interface LandingPageTemplateOption {
    value: string;
    label: string;
    description: string;
}

/**
 * Available Landing Page Templates
 *
 * Template keys must match the 'template' enum in database migration
 */
export const LANDING_PAGE_TEMPLATES: Record<string, TemplateMetadata> = {
    default_gfz: {
        key: 'default_gfz',
        name: 'Default GFZ Data Services',
        description: 'Standard template with all features',
        category: 'official',
        version: '1.0',
        resourceTypes: null, // Available for all resource types
    },
    default_gfz_igsn: {
        key: 'default_gfz_igsn',
        name: 'Default GFZ IGSN Template',
        description: 'Simplified template for physical samples (IGSN)',
        category: 'official',
        version: '1.0',
        resourceTypes: ['PhysicalObject'], // Only for IGSNs
    },
    external: {
        key: 'external',
        name: 'External Landing Page',
        description: 'Redirect to an external URL instead of generating a landing page',
        category: 'official',
        version: '1.0',
        resourceTypes: null, // Available for all resource types (null = no restrictions)
    },
} as const;

/**
 * Template Options for Select Dropdown
 *
 * Formats template metadata for form selects.
 * Can optionally filter by resource type.
 *
 * @param resourceType - Optional resource type to filter templates for
 * @returns Array of template options with value, label, and description
 */
export function getTemplateOptions(resourceType?: string): LandingPageTemplateOption[] {
    return Object.values(LANDING_PAGE_TEMPLATES)
        .filter((template) => {
            // If no resourceTypes restriction, template is available for all
            if (!template.resourceTypes) return true;
            // If no resourceType provided, only show unrestricted templates
            if (!resourceType) return !template.resourceTypes;
            // Check if resourceType is in the allowed list
            return template.resourceTypes.includes(resourceType);
        })
        .map((template) => ({
            value: template.key,
            label: template.name,
            description: template.description,
        }));
}

/**
 * Get IGSN-specific Template Options
 *
 * Returns only templates available for PhysicalObject resources (IGSNs)
 *
 * @returns Array of IGSN template options
 */
export function getIgsnTemplateOptions(): LandingPageTemplateOption[] {
    return getTemplateOptions('PhysicalObject');
}

/**
 * Get Default IGSN Template Key
 *
 * @returns Default template identifier for IGSNs ('default_gfz_igsn')
 */
export function getDefaultIgsnTemplate(): string {
    return 'default_gfz_igsn';
}

/**
 * Get Template Metadata by Key
 *
 * @param templateKey - Template identifier
 * @returns Template metadata or null if not found
 */
export function getTemplateMetadata(templateKey: string): TemplateMetadata | null {
    return LANDING_PAGE_TEMPLATES[templateKey] || null;
}

/**
 * Validate Template Key
 *
 * @param templateKey - Template identifier to validate
 * @returns True if template exists
 */
export function isValidTemplate(templateKey: string): boolean {
    return templateKey in LANDING_PAGE_TEMPLATES;
}

/**
 * Get Default Template Key
 *
 * @returns Default template identifier ('default_gfz')
 */
export function getDefaultTemplate(): string {
    return 'default_gfz';
}
