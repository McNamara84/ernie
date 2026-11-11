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
import { type DataCiteActionStatus, type DataCitePrefixConfig, describeOrcidReason, type OrcidPreflightIssue } from '@/lib/datacite-registration';

export type EditorDataCiteAction = 'register' | 'update';
export type EditorDataCiteSubmittingAction = 'submit' | 'retry' | 'override' | null;
export interface EditorDataCiteSubmission {
    prefix: string;
    force: boolean;
    submittingAction: Exclude<EditorDataCiteSubmittingAction, null>;
}

interface EditorDataCiteConfirmationDialogProps {
    open: boolean;
    action: EditorDataCiteAction;
    title?: string;
    doi?: string | null;
    hasLandingPage: boolean;
    initialPrefix?: string;
    resourceId?: number | null;
    isSubmitting: boolean;
    submittingAction: EditorDataCiteSubmittingAction;
    error: string | null;
    orcidBlockers: OrcidPreflightIssue[];
    orcidWarnings: OrcidPreflightIssue[];
    onClose: () => void;
    onConfirm: (submission: EditorDataCiteSubmission) => void;
}

export function EditorDataCiteConfirmationDialog({
    open,
    action,
    title,
    doi,
    hasLandingPage,
    initialPrefix = '',
    resourceId,
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
    const [actionStatus, setActionStatus] = useState<DataCiteActionStatus | null>(null);
    const [configurationError, setConfigurationError] = useState<string | null>(null);
    const hasExistingDoi = Boolean(doi?.trim());
    const isUpdate = action === 'update';
    const primaryLabel = isUpdate ? 'Update DataCite metadata' : 'Publish DOI and landing page';

    useEffect(() => {
        if (!open) {
            return;
        }

        let isCurrent = true;
        setConfigurationError(null);
        setActionStatus(null);
        setIsLoadingConfig(true);

        void Promise.all([
            axios.get<DataCitePrefixConfig>('/api/datacite/prefixes'),
            resourceId ? axios.get<DataCiteActionStatus>('/resources/' + resourceId + '/datacite-status') : Promise.resolve(null),
        ])
            .then(([response, status]) => {
                if (!isCurrent) return;
                if (status) setActionStatus(status.data);

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
    }, [hasExistingDoi, initialPrefix, open, resourceId]);

    const canSubmit =
        !isSubmitting &&
        !isLoadingConfig &&
        !configurationError &&
        (!resourceId || (isUpdate ? actionStatus?.can_update : actionStatus?.can_publish)) &&
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
                        Confirm {isUpdate ? 'DataCite update' : 'DOI and landing page publication'}
                    </DialogTitle>
                    <DialogDescription>
                        {isUpdate
                            ? `Confirm to save your editor changes in ERNIE, then update existing DOI ${doi ?? 'metadata'} at DataCite. The landing page must already be published and the DOI registration will be verified. Visibility stays unchanged. Local changes remain saved if the transfer fails.`
                            : 'Confirm to save your changes, publish the landing page, then publish the DOI at DataCite. An existing DOI is preserved. The landing page stays public if DataCite fails; the saved publication can be resumed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {actionStatus && (
                        <p role="status">
                            Saved DOI at DataCite: {actionStatus.state}. Environment: {actionStatus.mode}.
                            {isUpdate &&
                                !actionStatus.can_update &&
                                ' Metadata updates require a registered DOI and a published landing page. Use Publish DOI and landing page in the resource actions for explicit publication.'}
                        </p>
                    )}
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
                                {hasExistingDoi && ' The environment must be verified before any DataCite action. Please retry.'}
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
                                onClick={() => onConfirm({ prefix: selectedPrefix, force: false, submittingAction: 'retry' })}
                                data-testid="editor-orcid-preflight-retry"
                            >
                                Retry verification
                            </LoadingButton>
                            <LoadingButton
                                type="button"
                                loading={submittingAction === 'override'}
                                disabled={!canSubmit}
                                onClick={() => onConfirm({ prefix: selectedPrefix, force: true, submittingAction: 'override' })}
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
                            onClick={() => onConfirm({ prefix: selectedPrefix, force: false, submittingAction: 'submit' })}
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
