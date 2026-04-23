import { CloudUpload, Download } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';

export type ResourcesBulkExportFormat = 'datacite-json' | 'datacite-xml' | 'jsonld';

export interface ResourcesBulkActionsToolbarProps {
    selectedCount: number;
    onRegister: () => void;
    onExport: (format: ResourcesBulkExportFormat) => void;
    canRegister: boolean;
    isRegistering?: boolean;
    isExporting?: boolean;
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
}: ResourcesBulkActionsToolbarProps) {
    const hasSelection = selectedCount > 0;

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
                    <Button
                        type="button"
                        size="default"
                        onClick={onRegister}
                        disabled={!hasSelection || isRegistering || isExporting}
                        data-testid="bulk-register-button"
                    >
                        {isRegistering ? (
                            <>
                                <Spinner size="sm" className="mr-2" />
                                Registering...
                            </>
                        ) : (
                            <>
                                <CloudUpload className="mr-2 size-4" aria-hidden="true" />
                                Register Selected
                            </>
                        )}
                    </Button>
                )}

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            size="default"
                            variant="outline"
                            disabled={!hasSelection || isExporting || isRegistering}
                            data-testid="bulk-export-button"
                        >
                            {isExporting ? (
                                <>
                                    <Spinner size="sm" className="mr-2" />
                                    Exporting...
                                </>
                            ) : (
                                <>
                                    <Download className="mr-2 size-4" aria-hidden="true" />
                                    Export Selected
                                </>
                            )}
                        </Button>
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
