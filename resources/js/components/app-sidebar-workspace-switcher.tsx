import { SidebarGroup, useSidebar } from '@/components/ui/sidebar';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { type SidebarWorkspace } from '@/types';

interface AppSidebarWorkspaceSwitcherProps {
    value: SidebarWorkspace;
    onValueChange: (workspace: SidebarWorkspace) => void;
}

const workspaceLabels: Record<SidebarWorkspace, { label: string; shortLabel: string }> = {
    administration: {
        label: 'Administration',
        shortLabel: 'A',
    },
    curation: {
        label: 'Curation',
        shortLabel: 'C',
    },
};

export function AppSidebarWorkspaceSwitcher({ value, onValueChange }: AppSidebarWorkspaceSwitcherProps) {
    const { isMobile, state } = useSidebar();
    const isCompact = !isMobile && state === 'collapsed';

    return (
        <SidebarGroup className="px-2 pt-0">
            <Tabs
                value={value}
                onValueChange={(nextWorkspace) => {
                    if (nextWorkspace === 'curation' || nextWorkspace === 'administration') {
                        onValueChange(nextWorkspace);
                    }
                }}
                orientation={isCompact ? 'vertical' : 'horizontal'}
                className="w-full"
            >
                <TabsList
                    className={cn(
                        'grid w-full border border-sidebar-border/60 bg-sidebar-accent/35',
                        isCompact ? 'grid-cols-1 gap-1 p-1' : 'grid-cols-2',
                    )}
                >
                    {(['curation', 'administration'] as const).map((workspace) => {
                        const { label, shortLabel } = workspaceLabels[workspace];

                        return (
                            <TabsTrigger
                                key={workspace}
                                value={workspace}
                                aria-label={`${label} workspace`}
                                className={cn(
                                    'text-sidebar-foreground/70 data-[state=active]:text-sidebar-foreground',
                                    isCompact ? 'px-0 py-1.5 text-[11px] font-semibold tracking-[0.16em]' : 'px-3',
                                )}
                            >
                                {isCompact ? (
                                    <>
                                        <span aria-hidden="true">{shortLabel}</span>
                                        <span className="sr-only">{label}</span>
                                    </>
                                ) : (
                                    label
                                )}
                            </TabsTrigger>
                        );
                    })}
                </TabsList>
            </Tabs>
        </SidebarGroup>
    );
}