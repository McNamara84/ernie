import { Database, FlaskConical, Rocket } from 'lucide-react';

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

export type DocsTabId = 'getting-started' | 'datasets' | 'physical-samples';

interface DocsTabsProps {
    /** Currently active tab */
    activeTab: DocsTabId;
    /** Callback when tab changes */
    onTabChange: (tab: DocsTabId) => void;
    /** Additional CSS classes */
    className?: string;
}

/**
 * Tab configuration for documentation pages
 */
const tabConfig = [
    {
        id: 'getting-started' as const,
        label: 'Getting Started',
        icon: Rocket,
        description: 'Welcome, navigation, and general information',
    },
    {
        id: 'datasets' as const,
        label: 'Datasets',
        icon: Database,
        description: 'DOI curation workflow for research data',
    },
    {
        id: 'physical-samples' as const,
        label: 'Physical Samples',
        icon: FlaskConical,
        description: 'IGSN registration workflow for samples',
    },
];

/**
 * Documentation tabs navigation component with icons and descriptions.
 * Provides three main sections: Getting Started, Datasets (DOI), and Physical Samples (IGSN).
 *
 * Note: This is a pure navigation component. Content rendering is handled by the parent component.
 */
export function DocsTabs({ activeTab, onTabChange, className }: DocsTabsProps) {
    return (
        <Tabs value={activeTab} onValueChange={(value) => onTabChange(value as DocsTabId)} className={cn('w-full', className)}>
            <TabsList className="mb-6 grid h-auto w-full grid-cols-3 gap-2 bg-transparent p-0">
                {tabConfig.map((tab) => (
                    <TabsTrigger
                        key={tab.id}
                        value={tab.id}
                        data-testid={`tab-${tab.id}`}
                        className={cn(
                            'flex flex-col items-center gap-2 rounded-lg border bg-card p-4 transition-all',
                            'data-[state=active]:border-primary data-[state=active]:bg-primary/5 data-[state=active]:shadow-sm',
                            'hover:border-primary/50 hover:bg-accent/50',
                            'h-auto whitespace-normal',
                        )}
                    >
                        <div
                            className={cn(
                                'flex size-10 items-center justify-center rounded-full',
                                'bg-muted transition-colors',
                                'group-data-[state=active]:bg-primary/10',
                            )}
                        >
                            <tab.icon className="size-5 text-primary" />
                        </div>
                        <span className="text-sm font-semibold">{tab.label}</span>
                        <span className="hidden text-xs text-muted-foreground sm:block">{tab.description}</span>
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );
}

/**
 * Get tab configuration for external use
 */
export function getDocsTabConfig() {
    return tabConfig;
}
