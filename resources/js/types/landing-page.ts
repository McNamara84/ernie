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
    
    /** FTP URL for dataset downloads (optional) */
    ftp_url?: string | null;
    
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
        description: 'Classic GFZ Data Services landing page design with citation, authors, keywords, and location map',
        category: 'official',
        version: '1.0',
    },
    // Future templates will be added here:
    // modern_minimal: {
    //     key: 'modern_minimal',
    //     name: 'Modern Minimalist',
    //     description: 'Clean and modern design with smooth scrolling and animations',
    //     category: 'custom',
    //     version: '1.0',
    // },
} as const;

/**
 * Template Options for Select Dropdown
 * 
 * Formats template metadata for form selects
 * 
 * @returns Array of template options with value, label, and description
 */
export function getTemplateOptions(): LandingPageTemplateOption[] {
    return Object.values(LANDING_PAGE_TEMPLATES).map((template) => ({
        value: template.key,
        label: template.name,
        description: template.description,
    }));
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
