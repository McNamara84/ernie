import { Braces, ChevronDown, Eye, PencilLine, Quote, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { FileJsonIcon, FileXmlIcon } from '@/components/icons/file-icons';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export type ResourcesActionKey =
    | 'edit'
    | 'setup-landing-page'
    | 'manage-related-items'
    | 'export-datacite-json'
    | 'export-datacite-xml'
    | 'export-jsonld'
    | 'register-doi'
    | 'update-metadata'
    | 'delete';

export interface ResourcesActionState {
    visible?: boolean;
    available: boolean;
    reason?: string;
    loading?: boolean;
}

export interface ResourcesBulkActionsToolbarProps {
    selectedCount: number;
    actions: Record<ResourcesActionKey, ResourcesActionState>;
    onAction: (action: ResourcesActionKey) => void;
    onUnavailableAction: (reason: string) => void;
}

interface ActionDefinition {
    key: ResourcesActionKey;
    label: string;
    icon: ReactNode;
    variant?: 'default' | 'outline' | 'destructive';
}

const ACTION_DEFINITIONS: ActionDefinition[] = [
    {
        key: 'edit',
        label: 'Edit',
        icon: <PencilLine aria-hidden="true" className="size-4" />,
    },
    {
        key: 'setup-landing-page',
        label: 'Set up landing page',
        icon: <Eye aria-hidden="true" className="size-4" />,
        variant: 'outline',
    },
    {
        key: 'manage-related-items',
        label: 'Manage related items',
        icon: <Quote aria-hidden="true" className="size-4" />,
        variant: 'outline',
    },
    {
        key: 'export-datacite-json',
        label: 'Export DataCite JSON',
        icon: <FileJsonIcon aria-hidden="true" className="size-4" />,
        variant: 'outline',
    },
    {
        key: 'export-datacite-xml',
        label: 'Export DataCite XML',
        icon: <FileXmlIcon aria-hidden="true" className="size-4" />,
        variant: 'outline',
    },
    {
        key: 'export-jsonld',
        label: 'Export JSON-LD',
        icon: <Braces aria-hidden="true" className="size-4" />,
        variant: 'outline',
    },
    {
        key: 'register-doi',
        label: 'Register DOI',
        icon: <DataCiteIcon aria-hidden="true" className="size-4" />,
    },
    {
        key: 'update-metadata',
        label: 'Update metadata',
        icon: <DataCiteIcon aria-hidden="true" className="size-4" />,
        variant: 'outline',
    },
    {
        key: 'delete',
        label: 'Delete',
        icon: <Trash2 aria-hidden="true" className="size-4" />,
        variant: 'destructive',
    },
];

const DEFAULT_UNAVAILABLE_ACTION_REASON = 'This action is not available for the current selection.';

export function ResourcesBulkActionsToolbar({ selectedCount, actions, onAction, onUnavailableAction }: ResourcesBulkActionsToolbarProps) {
    const hasSelection = selectedCount > 0;
    const actionMenuTitle = hasSelection ? 'Actions' : 'Select rows to enable resource actions';
    const visibleActions = ACTION_DEFINITIONS.filter((definition) => actions[definition.key]?.visible !== false);

    return (
        <div
            data-testid="resources-bulk-actions-toolbar"
            className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-between"
        >
            <span className="text-sm text-muted-foreground" aria-live="polite">
                {hasSelection
                    ? `${selectedCount} ${selectedCount === 1 ? 'resource' : 'resources'} selected`
                    : 'Select rows to enable resource actions'}
            </span>

            {hasSelection ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="w-full justify-between sm:w-auto"
                            title={actionMenuTitle}
                            data-testid="resources-actions-menu-trigger"
                        >
                            <span>Actions</span>
                            <ChevronDown aria-hidden="true" className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-64">
                        {visibleActions.map((definition) => {
                            const state = actions[definition.key];
                            const isUnavailable = !state.available;
                            const isLoading = state.loading === true;
                            const unavailableReason = state.reason ?? DEFAULT_UNAVAILABLE_ACTION_REASON;

                            return (
                                <DropdownMenuItem
                                    key={definition.key}
                                    disabled={isLoading}
                                    aria-disabled={isLoading || undefined}
                                    data-unavailable={isUnavailable && !isLoading ? 'true' : undefined}
                                    title={isUnavailable ? unavailableReason : definition.label}
                                    data-testid={`resources-action-${definition.key}`}
                                    variant={definition.variant === 'destructive' ? 'destructive' : 'default'}
                                    className={cn(
                                        'items-start gap-2',
                                        isUnavailable && !isLoading && 'cursor-help opacity-60 focus:bg-background focus:text-foreground',
                                    )}
                                    onSelect={() => {
                                        if (isUnavailable) {
                                            onUnavailableAction(unavailableReason);
                                            return;
                                        }

                                        onAction(definition.key);
                                    }}
                                >
                                    {definition.icon}
                                    <span className="min-w-0 flex-1 truncate">{isLoading ? 'Working...' : definition.label}</span>
                                </DropdownMenuItem>
                            );
                        })}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="w-full justify-between sm:w-auto"
                    disabled
                    title={actionMenuTitle}
                    data-testid="resources-actions-menu-trigger"
                >
                    <span>Actions</span>
                    <ChevronDown aria-hidden="true" className="size-4" />
                </Button>
            )}
        </div>
    );
}
