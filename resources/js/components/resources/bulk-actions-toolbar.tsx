import { Braces, Eye, PencilLine, Quote, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { FileJsonIcon, FileXmlIcon } from '@/components/icons/file-icons';
import { Button } from '@/components/ui/button';
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

export function ResourcesBulkActionsToolbar({ selectedCount, actions, onAction, onUnavailableAction }: ResourcesBulkActionsToolbarProps) {
    const hasSelection = selectedCount > 0;

    return (
        <div data-testid="resources-bulk-actions-toolbar" className="flex flex-col gap-2">
            <span className="text-sm text-muted-foreground" aria-live="polite">
                {hasSelection
                    ? `${selectedCount} ${selectedCount === 1 ? 'resource' : 'resources'} selected`
                    : 'Select rows to enable resource actions'}
            </span>

            <div className="flex flex-wrap items-center gap-2">
                {ACTION_DEFINITIONS.map((definition) => {
                    const state = actions[definition.key];

                    if (state.visible === false) {
                        return null;
                    }

                    const isUnavailable = !state.available;
                    const isLoading = state.loading === true;

                    return (
                        <Button
                            key={definition.key}
                            type="button"
                            size="sm"
                            variant={definition.variant ?? 'default'}
                            aria-disabled={isUnavailable || undefined}
                            disabled={isLoading}
                            title={isUnavailable ? state.reason : definition.label}
                            data-testid={`resources-action-${definition.key}`}
                            className={cn('min-w-0', isUnavailable && 'cursor-not-allowed opacity-50 hover:bg-background hover:text-foreground')}
                            onClick={() => {
                                if (isUnavailable) {
                                    onUnavailableAction(state.reason ?? 'This action is not available for the current selection.');
                                    return;
                                }

                                onAction(definition.key);
                            }}
                        >
                            {definition.icon}
                            <span>{isLoading ? 'Working...' : definition.label}</span>
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}
