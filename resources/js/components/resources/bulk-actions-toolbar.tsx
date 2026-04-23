import { CloudUpload, Download } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { LoadingButton } from '@/components/ui/loading-button';

export type ResourcesBulkExportFormat = 'datacite-json' | 'datacite-xml' | 'jsonld';

export interface ResourcesBulkActionsToolbarProps {
    selectedCount: number;
    onRegister: () => void;
    onExport: (format: ResourcesBulkExportFormat) => void;
    canRegister: boolean;
    isRegistering?: boolean;
    isExporting?: boolean;
    /**
     * Human-readable reason why the register button must stay disabled despite
     * having a valid selection (e.g. selection contains DOI-less resources).
     * Empty/undefined means the button is enabled when a selection exists.
     */
    registerDisabledReason?: string;
}

/**
 * Bulk actions toolbar for the Resources page.
 *
 * Always rendered (so the page layout stays stable). Buttons are disabled
 * when no rows are selected. Heights match `size="default"` form inputs so
 * the toolbar visually aligns with the filter row above it.
 */
export function ResourcesBulkActionsToolbar({
    selectedCount,
    onRegister,
    onExport,
    canRegister,
    isRegistering = false,
    isExporting = false,
    registerDisabledReason,
}: ResourcesBulkActionsToolbarProps) {
    const hasSelection = selectedCount > 0;
    const registerBlocked = Boolean(registerDisabledReason);

    return (
        <div
            data-testid="resources-bulk-actions-toolbar"
            className="flex flex-wrap items-center gap-2"
        >
            <span className="text-sm text-muted-foreground" aria-live="polite">
                {hasSelection
                    ? `${selectedCount} ${selectedCount === 1 ? 'resource' : 'resources'} selected`
                    : 'Select rows to enable bulk actions'}
            </span>

            <div className="ml-auto flex items-center gap-2">
                {canRegister && (
                    <LoadingButton
                        type="button"
                        size="default"
                        onClick={onRegister}
                        disabled={!hasSelection || registerBlocked || isExporting}
                        loading={isRegistering}
                        title={registerBlocked ? registerDisabledReason : undefined}
                        data-testid="bulk-register-button"
                    >
                        {!isRegistering && <CloudUpload className="size-4" aria-hidden="true" />}
                        {isRegistering ? 'Registering...' : 'Register Selected'}
                    </LoadingButton>
                )}

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <LoadingButton
                            type="button"
                            size="default"
                            variant="outline"
                            disabled={!hasSelection || isRegistering}
                            loading={isExporting}
                            data-testid="bulk-export-button"
                        >
                            {!isExporting && <Download className="size-4" aria-hidden="true" />}
                            {isExporting ? 'Exporting...' : 'Export Selected'}
                        </LoadingButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => onExport('datacite-json')}>
                            DataCite JSON
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => onExport('datacite-xml')}>
                            DataCite XML
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => onExport('jsonld')}>
                            DataCite JSON-LD
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    );
}

