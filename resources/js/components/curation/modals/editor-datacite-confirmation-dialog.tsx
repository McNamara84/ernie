import axios from 'axios';
import { AlertCircle, AlertTriangle, CheckCircle2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LoadingButton } from '@/components/ui/loading-button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type DataCitePrefixConfig, describeOrcidReason, type OrcidPreflightIssue } from '@/lib/datacite-registration';

export type EditorDataCiteAction = 'register' | 'update';
export type EditorDataCiteSubmittingAction = 'submit' | 'retry' | 'override' | null;

interface EditorDataCiteConfirmationDialogProps {
    open: boolean;
    action: EditorDataCiteAction;
    title?: string;
    doi?: string | null;
    hasLandingPage: boolean;
    initialPrefix?: string;
    isSubmitting: boolean;
    submittingAction: EditorDataCiteSubmittingAction;
    error: string | null;
    orcidBlockers: OrcidPreflightIssue[];
    orcidWarnings: OrcidPreflightIssue[];
    onClose: () => void;
    onConfirm: (prefix: string, force: boolean, submittingAction: Exclude<EditorDataCiteSubmittingAction, null>) => void;
}

export function EditorDataCiteConfirmationDialog({
    open,
    action,
    title,
    doi,
    hasLandingPage,
    initialPrefix = '',
    isSubmitting,
    submittingAction,
    error,
    orcidBlockers,
    orcidWarnings,
    onClose,
    onConfirm,
}: EditorDataCiteConfirmationDialogProps) {
    const cancelButtonRef = useRef<HTMLButtonElement>(null);
    const [selectedPrefix, setSelectedPrefix] = useState(initialPrefix);
    const [availablePrefixes, setAvailablePrefixes] = useState<string[]>([]);
    const [isTestMode, setIsTestMode] = useState(true);
    const [isLoadingConfig, setIsLoadingConfig] = useState(false);
    const [configurationError, setConfigurationError] = useState<string | null>(null);
    const hasExistingDoi = Boolean(doi?.trim());
    const isUpdate = action === 'update';
    const primaryLabel = isUpdate ? 'Update metadata' : 'Register DOI';

    useEffect(() => {
        if (!open) {
            return;
        }

        let isCurrent = true;
        setConfigurationError(null);
        setIsLoadingConfig(true);

        void axios
            .get<DataCitePrefixConfig>('/api/datacite/prefixes')
            .then((response) => {
                if (!isCurrent) return;

                const prefixes = response.data.test_mode ? response.data.test : response.data.production;
                setIsTestMode(response.data.test_mode);
                setAvailablePrefixes(prefixes);
                if (!hasExistingDoi) {
                    setSelectedPrefix((current) => initialPrefix || current || prefixes[0] || '');
                }
            })
            .catch(() => {
                if (!isCurrent) return;

                setAvailablePrefixes([]);
                setConfigurationError('Failed to load DOI prefix configuration. Please check the DataCite settings.');
            })
            .finally(() => {
                if (isCurrent) setIsLoadingConfig(false);
            });

        return () => {
            isCurrent = false;
        };
    }, [hasExistingDoi, initialPrefix, open]);

    const canSubmit =
        !isSubmitting &&
        !isLoadingConfig &&
        (!configurationError || hasExistingDoi) &&
        orcidBlockers.length === 0 &&
        (hasExistingDoi || selectedPrefix !== '');

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && !isSubmitting && onClose()}>
            <DialogContent
                className="sm:max-w-[560px]"
                data-testid="editor-datacite-confirmation-dialog"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    cancelButtonRef.current?.focus();
                }}
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <DataCiteIcon className="size-5" />
                        Confirm {isUpdate ? 'DataCite update' : 'DOI registration'}
                    </DialogTitle>
                    <DialogDescription>
                        {isUpdate
                            ? `Are you sure you want to update ${doi ?? 'this DOI'} at DataCite? Your current editor changes will be saved first.`
                            : 'Are you sure you want to register this dataset at DataCite? Your current editor changes will be saved first.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {title && (
                        <Alert>
                            <CheckCircle2 className="size-4" />
                            <AlertTitle>Dataset</AlertTitle>
                            <AlertDescription>{title}</AlertDescription>
                        </Alert>
                    )}

                    {isTestMode && !isLoadingConfig && !configurationError && (
                        <Alert>
                            <AlertCircle className="size-4" />
                            <AlertTitle>Test mode active</AlertTitle>
                            <AlertDescription>This action targets the DataCite test environment.</AlertDescription>
                        </Alert>
                    )}

                    {!hasLandingPage && (
                        <Alert>
                            <AlertCircle className="size-4" />
                            <AlertTitle>Landing page setup follows</AlertTitle>
                            <AlertDescription>
                                After confirmation, the validated record is saved and the landing page setup opens. Registration resumes only after
                                that setup succeeds.
                            </AlertDescription>
                        </Alert>
                    )}

                    {hasExistingDoi && (
                        <Alert>
                            <CheckCircle2 className="size-4" />
                            <AlertTitle>Existing DOI</AlertTitle>
                            <AlertDescription>
                                Metadata for <strong>{doi}</strong> will be sent to DataCite.
                            </AlertDescription>
                        </Alert>
                    )}

                    {!hasExistingDoi && !isLoadingConfig && !configurationError && (
                        <div className="space-y-2">
                            <Label htmlFor="editor-prefix-selection">
                                DOI prefix <span className="text-xs text-muted-foreground">({isTestMode ? 'Test' : 'Production'})</span>
                            </Label>
                            <Select value={selectedPrefix} onValueChange={setSelectedPrefix} disabled={isSubmitting}>
                                <SelectTrigger id="editor-prefix-selection">
                                    <SelectValue placeholder="Select a prefix" />
                                </SelectTrigger>
                                <SelectContent>
                                    {availablePrefixes.map((prefix) => (
                                        <SelectItem key={prefix} value={prefix}>
                                            {prefix}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {availablePrefixes.length === 0 && <p className="text-sm text-muted-foreground">No prefixes are configured.</p>}
                        </div>
                    )}

                    {isLoadingConfig && <p className="py-3 text-center text-sm text-muted-foreground">Loading DataCite configuration...</p>}

                    {orcidBlockers.length > 0 && (
                        <Alert variant="destructive" data-testid="editor-orcid-preflight-blockers">
                            <AlertCircle className="size-4" />
                            <AlertTitle>ORCID validation failed</AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-5">
                                    {orcidBlockers.map((issue) => (
                                        <li key={`${issue.role}-${issue.position}-${issue.orcid}`}>
                                            <strong>{issue.displayName}</strong>: <code className="text-xs">{issue.orcid}</code> –{' '}
                                            {describeOrcidReason(issue.reason)}
                                        </li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {orcidBlockers.length === 0 && orcidWarnings.length > 0 && (
                        <Alert data-testid="editor-orcid-preflight-warnings">
                            <AlertTriangle className="size-4" />
                            <AlertTitle>ORCID verification unavailable</AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-5">
                                    {orcidWarnings.map((issue) => (
                                        <li key={`${issue.role}-${issue.position}-${issue.orcid}`}>
                                            <strong>{issue.displayName}</strong>: <code className="text-xs">{issue.orcid}</code> –{' '}
                                            {describeOrcidReason(issue.reason)}
                                        </li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {configurationError && (
                        <Alert variant={hasExistingDoi ? 'default' : 'destructive'}>
                            <AlertCircle className="size-4" />
                            <AlertTitle>{hasExistingDoi ? 'DOI prefix configuration unavailable' : 'DataCite action unavailable'}</AlertTitle>
                            <AlertDescription>
                                {configurationError}
                                {hasExistingDoi && ' Prefix configuration is not required to update an existing DOI.'}
                            </AlertDescription>
                        </Alert>
                    )}

                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>DataCite action failed</AlertTitle>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    <Button ref={cancelButtonRef} type="button" variant="outline" onClick={onClose} disabled={isSubmitting}>
                        Cancel
                    </Button>
                    {orcidBlockers.length === 0 && orcidWarnings.length > 0 ? (
                        <>
                            <LoadingButton
                                type="button"
                                variant="secondary"
                                loading={submittingAction === 'retry'}
                                disabled={!canSubmit}
                                onClick={() => onConfirm(selectedPrefix, false, 'retry')}
                                data-testid="editor-orcid-preflight-retry"
                            >
                                Retry verification
                            </LoadingButton>
                            <LoadingButton
                                type="button"
                                loading={submittingAction === 'override'}
                                disabled={!canSubmit}
                                onClick={() => onConfirm(selectedPrefix, true, 'override')}
                                data-testid="editor-orcid-preflight-override"
                            >
                                {isUpdate ? 'Update anyway' : 'Register anyway'}
                            </LoadingButton>
                        </>
                    ) : (
                        <LoadingButton
                            type="button"
                            loading={submittingAction === 'submit'}
                            disabled={!canSubmit}
                            onClick={() => onConfirm(selectedPrefix, false, 'submit')}
                            data-testid="confirm-editor-datacite-action"
                        >
                            {primaryLabel}
                        </LoadingButton>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
