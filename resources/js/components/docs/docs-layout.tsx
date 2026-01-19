import { useCallback, useMemo, useState } from 'react';

import { DocsSidebar, DocsSidebarMobile } from '@/components/docs/docs-sidebar';
import { type DocsTabId,DocsTabs } from '@/components/docs/docs-tabs';
import { useScrollSpy } from '@/hooks/use-scroll-spy';
import { cn } from '@/lib/utils';
import type { UserRole } from '@/types';
import type { DocSection, DocsSidebarItem, EditorSettings } from '@/types/docs';

interface DocsLayoutProps {
    /** Current user's role */
    userRole: UserRole;
    /** Editor settings for dynamic content */
    editorSettings: EditorSettings;
    /** Sections for Getting Started tab */
    gettingStartedSections: DocSection[];
    /** Sections for Datasets tab */
    datasetsSections: DocSection[];
    /** Sections for Physical Samples tab */
    physicalSamplesSections: DocSection[];
    /** Section content renderer */
    renderSection: (section: DocSection) => React.ReactNode;
    /** Additional CSS classes */
    className?: string;
}

/**
 * Role hierarchy for permission checking
 */
const roleHierarchy: Record<UserRole, number> = {
    beginner: 1,
    curator: 2,
    group_leader: 3,
    admin: 4,
};

/**
 * Main documentation layout component.
 * Combines tabs, sidebar navigation, and scroll-spy functionality.
 */
export function DocsLayout({
    userRole,
    editorSettings,
    gettingStartedSections,
    datasetsSections,
    physicalSamplesSections,
    renderSection,
    className,
}: DocsLayoutProps) {
    const [activeTab, setActiveTab] = useState<DocsTabId>('getting-started');

    // Get user's role level for filtering
    const userRoleLevel = roleHierarchy[userRole] ?? 1;

    /**
     * Filter sections based on user role and editor settings
     */
    const filterSections = useCallback(
        (sections: DocSection[]): DocSection[] => {
            return sections.filter((section) => {
                // Check role permission
                const sectionRoleLevel = roleHierarchy[section.minRole] ?? 1;
                if (sectionRoleLevel > userRoleLevel) {
                    return false;
                }

                // Check conditional visibility based on editor settings
                if (section.showIf && !section.showIf(editorSettings)) {
                    return false;
                }

                return true;
            });
        },
        [userRoleLevel, editorSettings],
    );

    // Filter sections for each tab
    const filteredGettingStarted = useMemo(() => filterSections(gettingStartedSections), [filterSections, gettingStartedSections]);
    const filteredDatasets = useMemo(() => filterSections(datasetsSections), [filterSections, datasetsSections]);
    const filteredPhysicalSamples = useMemo(() => filterSections(physicalSamplesSections), [filterSections, physicalSamplesSections]);

    // Get current tab's sections for sidebar
    const currentSections = useMemo(() => {
        switch (activeTab) {
            case 'getting-started':
                return filteredGettingStarted;
            case 'datasets':
                return filteredDatasets;
            case 'physical-samples':
                return filteredPhysicalSamples;
            default:
                return [];
        }
    }, [activeTab, filteredGettingStarted, filteredDatasets, filteredPhysicalSamples]);

    // Create sidebar items from current sections
    const sidebarItems: DocsSidebarItem[] = useMemo(
        () =>
            currentSections.map((section) => ({
                id: section.id,
                label: section.title,
                icon: section.icon,
            })),
        [currentSections],
    );

    // Get all section IDs for scroll-spy
    const sectionIds = useMemo(() => currentSections.map((s) => s.id), [currentSections]);

    // Track active section with scroll-spy
    const activeId = useScrollSpy(sectionIds);

    /**
     * Scroll to a section smoothly
     */
    const scrollToSection = useCallback((id: string) => {
        const element = document.getElementById(id);
        if (element) {
            const offset = 100; // Account for sticky header
            const elementPosition = element.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.scrollY - offset;

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth',
            });
        }
    }, []);

    /**
     * Handle tab change - reset scroll position
     */
    const handleTabChange = useCallback((tab: DocsTabId) => {
        setActiveTab(tab);
        // Scroll to top when changing tabs
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    /**
     * Render sections for a tab
     */
    const renderSections = useCallback(
        (sections: DocSection[]) => (
            <div className="space-y-12">
                {sections.map((section) => (
                    <div key={section.id}>{renderSection(section)}</div>
                ))}
            </div>
        ),
        [renderSection],
    );

    return (
        <div className={cn('mx-auto max-w-7xl', className)}>
            {/* Tabs Header */}
            <div className="mb-8">
                <DocsTabs
                    activeTab={activeTab}
                    onTabChange={handleTabChange}
                    gettingStartedContent={null}
                    datasetsContent={null}
                    physicalSamplesContent={null}
                />
            </div>

            {/* Mobile Sidebar */}
            <DocsSidebarMobile items={sidebarItems} activeId={activeId} onSectionClick={scrollToSection} />

            {/* Main Content Area with Sidebar */}
            <div className="flex gap-8">
                {/* Desktop Sidebar */}
                <DocsSidebar items={sidebarItems} activeId={activeId} onSectionClick={scrollToSection} />

                {/* Content */}
                <main className="min-w-0 flex-1">
                    {activeTab === 'getting-started' && renderSections(filteredGettingStarted)}
                    {activeTab === 'datasets' && renderSections(filteredDatasets)}
                    {activeTab === 'physical-samples' && renderSections(filteredPhysicalSamples)}
                </main>
            </div>
        </div>
    );
}

/**
 * Utility to check if user has minimum required role
 */
export function hasMinRole(userRole: UserRole, minRole: UserRole): boolean {
    return (roleHierarchy[userRole] ?? 1) >= (roleHierarchy[minRole] ?? 1);
}
