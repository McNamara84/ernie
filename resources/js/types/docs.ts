/**
 * Editor settings passed from backend for dynamic documentation content.
 * Used to show/hide documentation sections based on what features are actually active.
 */
export interface EditorSettings {
    /**
     * GCMD thesaurus active states
     */
    thesauri: {
        /** Is GCMD Science Keywords thesaurus active? */
        scienceKeywords: boolean;
        /** Is GCMD Platforms thesaurus active? */
        platforms: boolean;
        /** Is GCMD Instruments thesaurus active? */
        instruments: boolean;
    };
    /**
     * Feature availability flags
     */
    features: {
        /** Is at least one GCMD thesaurus active? */
        hasActiveGcmd: boolean;
        /** Is MSL vocabulary available? */
        hasActiveMsl: boolean;
        /** Is at least one license active? */
        hasActiveLicenses: boolean;
        /** Is at least one resource type active? */
        hasActiveResourceTypes: boolean;
        /** Is at least one title type active? */
        hasActiveTitleTypes: boolean;
        /** Is at least one language active? */
        hasActiveLanguages: boolean;
    };
    /**
     * Editor limits
     */
    limits: {
        /** Maximum number of titles per resource */
        maxTitles: number;
        /** Maximum number of licenses per resource */
        maxLicenses: number;
    };
}

/**
 * Props passed to the documentation page from DocsController
 */
export interface DocsPageProps {
    /** Current user's role */
    userRole: import('@/types').UserRole;
    /** Editor settings for dynamic content */
    editorSettings: EditorSettings;
}

/**
 * Documentation section definition
 */
export interface DocSection {
    /** Unique section identifier (used for scroll-spy) */
    id: string;
    /** Section title */
    title: string;
    /** Icon component to display (required for consistent UI) */
    icon: React.ComponentType<{ className?: string }>;
    /** Minimum role required to see this section */
    minRole: import('@/types').UserRole;
    /** Optional condition to show section based on editor settings */
    showIf?: (settings: EditorSettings) => boolean;
    /** Section content */
    content: React.ReactNode;
}

/**
 * Documentation tab definition
 */
export interface DocTab {
    /** Unique tab identifier */
    id: string;
    /** Tab label */
    label: string;
    /** Icon component for tab */
    icon: React.ComponentType<{ className?: string }>;
    /** Sections within this tab */
    sections: DocSection[];
}

/**
 * Sidebar navigation item
 */
export interface DocsSidebarItem {
    /** Section ID to scroll to */
    id: string;
    /** Display label */
    label: string;
    /** Icon component */
    icon?: React.ComponentType<{ className?: string }>;
    /** Nested items */
    children?: DocsSidebarItem[];
}
