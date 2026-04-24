import { usePage } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertCircle, AlertTriangle, CheckCircle2, GraduationCap } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LoadingButton } from '@/components/ui/loading-button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type User as AuthUser } from '@/types';

interface Resource {
    id: number;
    doi?: string | null;
    title?: string;
    landingPage?: {
        id: number;
        status: string;
        public_url: string;
    } | null;
    [key: string]: unknown;
}

interface RegisterDoiModalProps {
    resource: Resource;
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: (doi: string) => void;
}

interface DoiRegistrationResponse {
    success: boolean;
    message: string;
    doi: string;
    mode: 'test' | 'production';
    updated: boolean;
    error?: string;
    details?: unknown;
}

/**
 * Reason codes emitted by {@see \App\Services\Orcid\OrcidPreflightValidator}.
 *
 * `blocking` reasons (not_found / checksum / format) prevent registration.
 * Warning reasons (network / timeout / api_error / unknown) can be overridden
 * by the curator via "Register anyway", which re-posts with `force: true`.
 */
type OrcidPreflightReason = 'not_found' | 'checksum' | 'format' | 'network' | 'timeout' | 'api_error' | 'unknown';

interface OrcidPreflightIssue {
    severity: 'blocking' | 'warning';
    reason: OrcidPreflightReason;
    role: 'creator' | 'contributor';
    position: number;
    orcid: string;
    displayName: string;
}

interface OrcidPreflightPayload {
    error: 'orcid_validation_failed' | 'orcid_validation_warning';
    message?: string;
    invalid: OrcidPreflightIssue[];
    warnings: OrcidPreflightIssue[];
}

interface PrefixConfig {
    test: string[];
    production: string[];
    test_mode: boolean;
}

const ORCID_REASON_LABELS: Record<OrcidPreflightReason, string> = {
    not_found: 'not found in ORCID registry',
    checksum: 'invalid ORCID checksum',
    format: 'malformed ORCID identifier',
    network: 'ORCID service unreachable',
    timeout: 'ORCID service timed out',
    api_error: 'ORCID service reported an error',
    unknown: 'ORCID verification failed for an unknown reason',
};

function isOrcidPreflightPayload(data: unknown): data is OrcidPreflightPayload {
    if (!data || typeof data !== 'object') {
        return false;
    }
    const { error, invalid, warnings } = data as {
        error?: unknown;
        invalid?: unknown;
        warnings?: unknown;
    };
    if (error !== 'orcid_validation_failed' && error !== 'orcid_validation_warning') {
        return false;
    }
    // Both fields are always emitted by the backend, but some proxies strip
    // empty arrays. Accept missing fields, but reject anything that is present
    // and not an array to avoid storing non-iterable values in state.
    if (invalid !== undefined && !Array.isArray(invalid)) {
        return false;
    }
    if (warnings !== undefined && !Array.isArray(warnings)) {
        return false;
    }
    return true;
}

export default function RegisterDoiModal({ resource, isOpen, onClose, onSuccess }: RegisterDoiModalProps) {
    const { auth } = usePage<{ auth: { user: AuthUser } }>().props;
    const [selectedPrefix, setSelectedPrefix] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    // Tracks which action is currently in flight so only the clicked button
    // renders its loading indicator. The Cancel button and form inputs remain
    // gated by the broader `isSubmitting` flag.
    const [submittingAction, setSubmittingAction] = useState<'submit' | 'retry' | 'override' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [availablePrefixes, setAvailablePrefixes] = useState<string[]>([]);
    const [isTestMode, setIsTestMode] = useState<boolean>(true);
    const [isLoadingConfig, setIsLoadingConfig] = useState(true);
    // ORCID preflight state (see issue #610). `orcidBlockers` are hard blockers
    // surfaced by the backend; `orcidWarnings` are transient failures the
    // curator may override via the "Register anyway" button.
    const [orcidBlockers, setOrcidBlockers] = useState<OrcidPreflightIssue[]>([]);
    const [orcidWarnings, setOrcidWarnings] = useState<OrcidPreflightIssue[]>([]);

    const hasExistingDoi = Boolean(resource.doi);
    const hasLandingPage = Boolean(resource.landingPage);
    const isBeginner = auth.user?.role === 'beginner';

    // Load available prefixes from backend config
    useEffect(() => {
        if (isOpen) {
            loadPrefixConfiguration();
        } else {
            // Reset state when modal closes
            setSelectedPrefix('');
            setError(null);
            setIsSubmitting(false);
            setSubmittingAction(null);
            setOrcidBlockers([]);
            setOrcidWarnings([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen]);

    const loadPrefixConfiguration = async () => {
        setIsLoadingConfig(true);
        try {
            // Get prefix configuration from backend
            const response = await axios.get<PrefixConfig>('/api/datacite/prefixes');

            // Use test mode from backend configuration
            const testMode = response.data.test_mode;
            setIsTestMode(testMode);

            // Set available prefixes based on mode
            const prefixes = testMode ? response.data.test : response.data.production;
            setAvailablePrefixes(prefixes);

            // Select first prefix by default if registering new DOI
            if (!hasExistingDoi && prefixes.length > 0) {
                setSelectedPrefix(prefixes[0]);
            }
        } catch (err) {
            console.error('Failed to load prefix configuration:', err);
            // Don't use hardcoded fallback prefixes to avoid drift from backend config
            // Instead, show error state - user should fix backend configuration
            setAvailablePrefixes([]);
            setIsTestMode(true);
            setError('Failed to load DOI prefix configuration. Please check DataCite settings.');
        } finally {
            setIsLoadingConfig(false);
        }
    };

    const handleSubmit = async (force = false, action: 'submit' | 'retry' | 'override' = 'submit') => {
        setError(null);

        // Validation
        if (!hasLandingPage) {
            setError('A landing page must be created before registering a DOI.');
            return;
        }

        if (!hasExistingDoi && !selectedPrefix) {
            setError('Please select a DOI prefix.');
            return;
        }

        setIsSubmitting(true);
        setSubmittingAction(action);

        try {
            const response = await axios.post<DoiRegistrationResponse>(`/resources/${resource.id}/register-doi`, {
                prefix: selectedPrefix,
                force,
            });

            const { doi, updated, mode } = response.data;

            // Show success toast
            const modeLabel = mode === 'test' ? 'Test' : 'Production';
            const actionLabel = updated ? 'updated' : 'registered';
            toast.success(`DOI ${actionLabel} successfully`, {
                description: `${modeLabel} DOI: ${doi}`,
                duration: 5000,
            });

            // Clear ORCID preflight state on success
            setOrcidBlockers([]);
            setOrcidWarnings([]);

            // Close modal first to ensure UI is updated
            onClose();

            // Call success callback after modal closes
            // Wrapped in try-catch to prevent callback errors from affecting modal state
            if (onSuccess) {
                try {
                    onSuccess(doi);
                } catch (callbackError) {
                    // Log callback errors with warning level to make them visible
                    // This helps debug issues in the callback implementation
                    console.warn(
                        'Warning: Error in DOI registration success callback. This may indicate a bug in the callback implementation.',
                        callbackError,
                    );
                }
            }
        } catch (err) {
            // ORCID preflight (see issue #610): the backend returns 422 for
            // confirmed-invalid ORCIDs and 409 for transient failures that
            // require explicit curator confirmation.
            if (isAxiosError(err) && err.response && isOrcidPreflightPayload(err.response.data)) {
                const payload = err.response.data;
                setOrcidBlockers(Array.isArray(payload.invalid) ? payload.invalid : []);
                setOrcidWarnings(Array.isArray(payload.warnings) ? payload.warnings : []);
                setError(null);
                setIsSubmitting(false);
                setSubmittingAction(null);
                return;
            }

            console.error('DOI registration failed:', err);

            let errorMessage = 'Failed to register DOI. Please try again.';

            if (isAxiosError(err) && err.response?.data) {
                const errorData = err.response.data as DoiRegistrationResponse;
                errorMessage = errorData.message || errorMessage;

                // Log detailed error in development
                if (import.meta.env.DEV && errorData.details) {
                    console.error('DataCite API error details:', errorData.details);
                }
            }

            setError(errorMessage);
        } finally {
            setIsSubmitting(false);
            setSubmittingAction(null);
        }
    };

    const handleClose = () => {
        if (!isSubmitting) {
            onClose();
        }
    };

    const handleOpenChange = (open: boolean) => {
        // Radix Dialog calls onOpenChange for both opening and closing events.
        // We only handle close requests here because the parent controls opening
        // via the isOpen prop. This also prevents closing during submission.
        if (!open) {
            handleClose();
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <DataCiteIcon className="size-5" />
                        {hasExistingDoi ? 'Update DOI Metadata' : 'Register DOI with DataCite'}
                    </DialogTitle>
                    <DialogDescription>
                        {hasExistingDoi ? `Update metadata for existing DOI: ${resource.doi}` : 'Register a new DOI for this resource with DataCite.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-4">
                    {/* Landing Page Check */}
                    {!hasLandingPage && (
                        <Alert variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>Landing Page Required</AlertTitle>
                            <AlertDescription>
                                A landing page must be created before you can register a DOI. Please set up a landing page first using the eye icon.
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Test Mode Warning */}
                    {isTestMode && hasLandingPage && (
                        <Alert>
                            <AlertCircle className="size-4" />
                            <AlertTitle>Test Mode Active</AlertTitle>
                            <AlertDescription>
                                You are using the DataCite test environment. DOIs registered in test mode are not permanent and should only be used
                                for testing purposes.
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Beginner User Notice */}
                    {isBeginner && hasLandingPage && (
                        <Alert variant="default">
                            <GraduationCap className="size-4" />
                            <AlertTitle>Beginner Mode</AlertTitle>
                            <AlertDescription>
                                As a beginner user, you can only register test DOIs. Contact an admin or group leader to be promoted to curator role
                                for production DOI registration.
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Existing DOI Info */}
                    {hasExistingDoi && (
                        <Alert>
                            <CheckCircle2 className="size-4" />
                            <AlertTitle>Existing DOI</AlertTitle>
                            <AlertDescription>
                                This resource already has a DOI: <strong>{resource.doi}</strong>
                                <br />
                                Submitting will update the metadata at DataCite.
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Prefix Selection (only for new DOIs) */}
                    {!hasExistingDoi && hasLandingPage && !isLoadingConfig && (
                        <div className="space-y-3">
                            <Label htmlFor="prefix-selection">
                                Select DOI Prefix <span className="text-xs text-muted-foreground">({isTestMode ? 'Test' : 'Production'} Mode)</span>
                            </Label>
                            <Select value={selectedPrefix} onValueChange={setSelectedPrefix} disabled={isSubmitting}>
                                <SelectTrigger id="prefix-selection">
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
                            {availablePrefixes.length === 0 && (
                                <p className="text-sm text-muted-foreground" role="alert">
                                    No prefixes available. Please check your DataCite configuration.
                                </p>
                            )}
                        </div>
                    )}

                    {/* Loading State */}
                    {isLoadingConfig && (
                        <div className="flex items-center justify-center py-4">
                            <div className="animate-pulse text-muted-foreground">Loading configuration...</div>
                        </div>
                    )}

                    {/* ORCID Preflight Blockers (see issue #610) */}
                    {orcidBlockers.length > 0 && (
                        <Alert variant="destructive" data-testid="orcid-preflight-blockers">
                            <AlertCircle className="size-4" />
                            <AlertTitle>
                                ORCID validation failed ({orcidBlockers.length}{' '}
                                {orcidBlockers.length === 1 ? 'identifier' : 'identifiers'})
                            </AlertTitle>
                            <AlertDescription>
                                <p className="mb-2">
                                    The following ORCID identifiers could not be verified. Please correct them in the editor before registering
                                    the DOI.
                                </p>
                                <ul className="list-disc space-y-1 pl-5">
                                    {orcidBlockers.map((issue) => (
                                        <li key={`${issue.role}-${issue.position}-${issue.orcid}`}>
                                            <strong>{issue.displayName}</strong>{' '}
                                            <span className="text-xs text-muted-foreground">({issue.role})</span>
                                            <br />
                                            <code className="text-xs">{issue.orcid}</code> – {ORCID_REASON_LABELS[issue.reason]}
                                        </li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* ORCID Preflight Warnings (transient failures, curator may override) */}
                    {orcidBlockers.length === 0 && orcidWarnings.length > 0 && (
                        <Alert data-testid="orcid-preflight-warnings">
                            <AlertTriangle className="size-4" />
                            <AlertTitle>
                                ORCID verification unavailable ({orcidWarnings.length}{' '}
                                {orcidWarnings.length === 1 ? 'identifier' : 'identifiers'})
                            </AlertTitle>
                            <AlertDescription>
                                <p className="mb-2">
                                    The ORCID service is temporarily unreachable for the following identifiers. You can retry or register anyway
                                    if you are confident the ORCIDs are correct.
                                </p>
                                <ul className="list-disc space-y-1 pl-5">
                                    {orcidWarnings.map((issue) => (
                                        <li key={`${issue.role}-${issue.position}-${issue.orcid}`}>
                                            <strong>{issue.displayName}</strong>{' '}
                                            <span className="text-xs text-muted-foreground">({issue.role})</span>
                                            <br />
                                            <code className="text-xs">{issue.orcid}</code> – {ORCID_REASON_LABELS[issue.reason]}
                                        </li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Error Display */}
                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>Error</AlertTitle>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={handleClose} disabled={isSubmitting}>
                        Cancel
                    </Button>
                    {orcidBlockers.length === 0 && orcidWarnings.length > 0 ? (
                        <>
                            <LoadingButton
                                variant="secondary"
                                loading={submittingAction === 'retry'}
                                onClick={() => {
                                    // Keep the warning state visible while the retry is in flight so
                                    // this button stays mounted and its loading indicator is shown.
                                    // On success, handleSubmit clears the warnings before closing the
                                    // modal; on another failure, the catch branch repopulates them.
                                    handleSubmit(false, 'retry');
                                }}
                                disabled={isSubmitting || !hasLandingPage || (!hasExistingDoi && !selectedPrefix) || isLoadingConfig}
                                data-testid="orcid-preflight-retry"
                            >
                                Retry verification
                            </LoadingButton>
                            <LoadingButton
                                loading={submittingAction === 'override'}
                                onClick={() => handleSubmit(true, 'override')}
                                disabled={isSubmitting || !hasLandingPage || (!hasExistingDoi && !selectedPrefix) || isLoadingConfig}
                                data-testid="orcid-preflight-override"
                            >
                                Register anyway
                            </LoadingButton>
                        </>
                    ) : (
                        <LoadingButton
                            loading={submittingAction === 'submit'}
                            onClick={() => handleSubmit()}
                            disabled={
                                isSubmitting ||
                                !hasLandingPage ||
                                (!hasExistingDoi && !selectedPrefix) ||
                                isLoadingConfig ||
                                orcidBlockers.length > 0
                            }
                        >
                            {hasExistingDoi ? 'Update Metadata' : 'Register DOI'}
                        </LoadingButton>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
