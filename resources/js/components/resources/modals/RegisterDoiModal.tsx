import axios, { isAxiosError } from 'axios';
import { AlertCircle, CheckCircle2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { withBasePath } from '@/lib/base-path';

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

interface PrefixConfig {
    test: string[];
    production: string[];
    test_mode: boolean;
}

export default function RegisterDoiModal({
    resource,
    isOpen,
    onClose,
    onSuccess,
}: RegisterDoiModalProps) {
    const [selectedPrefix, setSelectedPrefix] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [availablePrefixes, setAvailablePrefixes] = useState<string[]>([]);
    const [isTestMode, setIsTestMode] = useState<boolean>(true);
    const [isLoadingConfig, setIsLoadingConfig] = useState(true);

    const hasExistingDoi = Boolean(resource.doi);
    const hasLandingPage = Boolean(resource.landingPage);

    // Load available prefixes from backend config
    useEffect(() => {
        if (isOpen) {
            loadPrefixConfiguration();
        } else {
            // Reset state when modal closes
            setSelectedPrefix('');
            setError(null);
            setIsSubmitting(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen]);

    const loadPrefixConfiguration = async () => {
        setIsLoadingConfig(true);
        try {
            // Get prefix configuration from backend
            const response = await axios.get<PrefixConfig>(
                withBasePath('/api/datacite/prefixes')
            );

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

    const handleSubmit = async () => {
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

        try {
            const response = await axios.post<DoiRegistrationResponse>(
                withBasePath(`/resources/${resource.id}/register-doi`),
                {
                    prefix: selectedPrefix,
                }
            );

            const { doi, updated, mode } = response.data;

            // Show success toast
            const modeLabel = mode === 'test' ? 'Test' : 'Production';
            const action = updated ? 'updated' : 'registered';
            toast.success(
                `DOI ${action} successfully`,
                {
                    description: `${modeLabel} DOI: ${doi}`,
                    duration: 5000,
                }
            );

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
                        callbackError
                    );
                }
            }

        } catch (err) {
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
        }
    };

    const handleClose = () => {
        if (!isSubmitting) {
            onClose();
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={handleClose}>
            <DialogContent className="sm:max-w-[525px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <DataCiteIcon className="size-5" />
                        {hasExistingDoi ? 'Update DOI Metadata' : 'Register DOI with DataCite'}
                    </DialogTitle>
                    <DialogDescription>
                        {hasExistingDoi
                            ? `Update metadata for existing DOI: ${resource.doi}`
                            : 'Register a new DOI for this resource with DataCite.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-4">
                    {/* Landing Page Check */}
                    {!hasLandingPage && (
                        <Alert variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>Landing Page Required</AlertTitle>
                            <AlertDescription>
                                A landing page must be created before you can register a DOI. Please
                                set up a landing page first using the eye icon.
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Test Mode Warning */}
                    {isTestMode && hasLandingPage && (
                        <Alert>
                            <AlertCircle className="size-4" />
                            <AlertTitle>Test Mode Active</AlertTitle>
                            <AlertDescription>
                                You are using the DataCite test environment. DOIs registered in test
                                mode are not permanent and should only be used for testing purposes.
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
                                Select DOI Prefix{' '}
                                <span className="text-muted-foreground text-xs">
                                    ({isTestMode ? 'Test' : 'Production'} Mode)
                                </span>
                            </Label>
                            <Select
                                value={selectedPrefix}
                                onValueChange={setSelectedPrefix}
                                disabled={isSubmitting}
                            >
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
                            <div className="animate-pulse text-muted-foreground">
                                Loading configuration...
                            </div>
                        </div>
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
                    <Button
                        onClick={handleSubmit}
                        disabled={
                            isSubmitting ||
                            !hasLandingPage ||
                            (!hasExistingDoi && !selectedPrefix) ||
                            isLoadingConfig
                        }
                    >
                        {isSubmitting ? (
                            <>
                                <span className="mr-2" aria-live="polite">
                                    Processing...
                                </span>
                                <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-solid border-current border-r-transparent motion-reduce:animate-[spin_1.5s_linear_infinite]" role="status" aria-label="Loading">
                                    <span className="sr-only">Loading</span>
                                </span>
                            </>
                        ) : hasExistingDoi ? (
                            'Update Metadata'
                        ) : (
                            'Register DOI'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
