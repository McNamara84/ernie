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
    variant?: 'destructive';
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
    },
    {
        key: 'manage-related-items',
        label: 'Manage Related Items',
        icon: <Quote aria-hidden="true" className="size-4" />,
    },
    {
        key: 'export-datacite-json',
        label: 'Export DataCite JSON',
        icon: <FileJsonIcon aria-hidden="true" className="size-4" />,
    },
    {
        key: 'export-datacite-xml',
        label: 'Export DataCite XML',
        icon: <FileXmlIcon aria-hidden="true" className="size-4" />,
    },
    {
        key: 'export-jsonld',
        label: 'Export JSON-LD',
        icon: <Braces aria-hidden="true" className="size-4" />,
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
    },
    {
        key: 'delete',
        label: 'Delete',
        icon: <Trash2 aria-hidden="true" className="size-4" />,
        variant: 'destructive',
    },
];

const DEFAULT_UNAVAILABLE_ACTION_REASON = 'This action is not available for the current selection.';
const QUICK_ACTION_KEYS = ['edit', 'setup-landing-page'] satisfies ResourcesActionKey[];
const QUICK_ACTION_KEY_SET = new Set<ResourcesActionKey>(QUICK_ACTION_KEYS);

const getActionDefinition = (key: ResourcesActionKey): ActionDefinition => {
    const definition = ACTION_DEFINITIONS.find((actionDefinition) => actionDefinition.key === key);

    if (!definition) {
        throw new Error(`Unknown resource action: ${key}`);
    }

    return definition;
};

export function ResourcesBulkActionsToolbar({ selectedCount, actions, onAction, onUnavailableAction }: ResourcesBulkActionsToolbarProps) {
    const hasSelection = selectedCount > 0;
    const actionMenuTitle = hasSelection ? 'Actions' : 'Select rows to enable resource actions';
    const visibleQuickActions = QUICK_ACTION_KEYS.map(getActionDefinition).filter((definition) => actions[definition.key]?.visible !== false);
    const visibleMenuActions = ACTION_DEFINITIONS.filter(
        (definition) => !QUICK_ACTION_KEY_SET.has(definition.key) && actions[definition.key]?.visible !== false,
    );

    const executeAction = (definition: ActionDefinition): void => {
        const state = actions[definition.key];
        const unavailableReason = state.reason ?? DEFAULT_UNAVAILABLE_ACTION_REASON;

        if (!state.available) {
            onUnavailableAction(unavailableReason);
            return;
        }

        onAction(definition.key);
    };

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

            <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                {visibleQuickActions.map((definition) => {
                    const state = actions[definition.key];
                    const isUnavailable = !state.available;
                    const isLoading = state.loading === true;
                    const unavailableReason = state.reason ?? DEFAULT_UNAVAILABLE_ACTION_REASON;

                    return (
                        <Button
                            key={definition.key}
                            type="button"
                            size="sm"
                            variant="outline"
                            className={cn('w-full justify-center gap-2 sm:w-auto', isUnavailable && hasSelection && !isLoading && 'opacity-70')}
                            disabled={!hasSelection || isLoading}
                            title={!hasSelection ? actionMenuTitle : isUnavailable ? unavailableReason : definition.label}
                            data-testid={`resources-action-${definition.key}`}
                            data-unavailable={isUnavailable && hasSelection && !isLoading ? 'true' : undefined}
                            onClick={() => executeAction(definition)}
                        >
                            {definition.icon}
                            <span className="truncate">{isLoading ? 'Working...' : definition.label}</span>
                        </Button>
                    );
                })}

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
                            {visibleMenuActions.map((definition) => {
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
                                        variant={definition.variant ?? 'default'}
                                        className={cn(
                                            'items-start gap-2',
                                            isUnavailable && !isLoading && 'cursor-help opacity-60 focus:bg-background focus:text-foreground',
                                        )}
                                        onSelect={() => executeAction(definition)}
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
        </div>
    );
}
