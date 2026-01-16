import axios, { isAxiosError } from 'axios';
import { Copy, Eye, Globe } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { getDefaultTemplate, getTemplateOptions, type LandingPageConfig } from '@/types/landing-page';

interface Resource {
    id: number;
    doi?: string | null;
    title?: string;
    [key: string]: unknown;
}

interface SetupLandingPageModalProps {
    resource: Resource;
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    existingConfig?: LandingPageConfig | null;
}

export default function SetupLandingPageModal({ resource, isOpen, onClose, onSuccess, existingConfig }: SetupLandingPageModalProps) {
    const [template, setTemplate] = useState<string>(existingConfig?.template ?? getDefaultTemplate());
    const [ftpUrl, setFtpUrl] = useState<string>(existingConfig?.ftp_url ?? '');
    const [isPublished, setIsPublished] = useState<boolean>((existingConfig?.status ?? 'draft') === 'published');
    const [previewUrl, setPreviewUrl] = useState<string>(existingConfig?.preview_url ?? '');
    const [isLoading, setIsLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [currentConfig, setCurrentConfig] = useState<LandingPageConfig | null>(existingConfig ?? null);

    // Load existing config when modal opens
    useEffect(() => {
        if (isOpen && resource.id) {
            if (existingConfig) {
                // Use existing config passed as prop
                setCurrentConfig(existingConfig);
                setTemplate(existingConfig.template);
                setFtpUrl(existingConfig.ftp_url ?? '');
                setIsPublished(existingConfig.status === 'published');
                setPreviewUrl(existingConfig.preview_url ?? '');
            } else {
                // Load from server
                loadLandingPageConfig();
            }
        } else if (!isOpen) {
            // Reset state when modal closes
            setCurrentConfig(null);
            setTemplate(getDefaultTemplate());
            setFtpUrl('');
            setIsPublished(false);
            setPreviewUrl('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, resource.id]);

    const loadLandingPageConfig = async () => {
        setIsLoading(true);
        try {
            const response = await axios.get<{ landing_page: LandingPageConfig }>(`/resources/${resource.id}/landing-page`);
            const config = response.data.landing_page;
            setCurrentConfig(config);
            setTemplate(config.template);
            setFtpUrl(config.ftp_url ?? '');
            setIsPublished(config.status === 'published');
            setPreviewUrl(config.preview_url);
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) {
                // No landing page exists yet, use defaults
                setCurrentConfig(null);
                setTemplate('default_gfz');
                setFtpUrl('');
                setIsPublished(false);
                setPreviewUrl('');
            } else {
                console.error('Failed to load landing page config:', error);
                toast.error('Failed to load landing page configuration');
            }
        } finally {
            setIsLoading(false);
        }
    };

    const handleSave = async () => {
        if (!resource.id) {
            toast.error('Resource ID is missing');
            return;
        }

        setIsSaving(true);

        try {
            const payload = {
                template,
                ftp_url: ftpUrl || null,
                status: isPublished ? 'published' : 'draft',
            };

            const url = `/resources/${resource.id}/landing-page`;

            // Determine if we should update or create
            const shouldUpdate = currentConfig !== null;

            const response = shouldUpdate
                ? await axios.put<{
                      message: string;
                      landing_page: LandingPageConfig;
                  }>(url, payload)
                : await axios.post<{
                      message: string;
                      landing_page: LandingPageConfig;
                      preview_url: string;
                  }>(url, payload);

            toast.success(response.data.message);

            // Update local state with response data
            if (response.data.landing_page) {
                const updatedConfig = response.data.landing_page;
                setCurrentConfig(updatedConfig);
                setPreviewUrl(updatedConfig.preview_url ?? '');
                setIsPublished(updatedConfig.status === 'published');
            }

            // Clear session-based preview if it exists
            try {
                await axios.delete(`/resources/${resource.id}/landing-page/preview`);
            } catch {
                // Ignore errors from clearing preview session
            }

            // Call success callback to notify parent component
            onSuccess?.();
        } catch (error) {
            console.error('Failed to save landing page:', error);

            let errorMessage = 'Failed to save landing page configuration';
            if (isAxiosError(error) && error.response?.data?.message) {
                errorMessage = error.response.data.message;
            } else if (isAxiosError(error) && error.response?.data?.errors) {
                const errors = error.response.data.errors;
                errorMessage = Object.values(errors).flat().join(', ');
            }

            toast.error(errorMessage);
        } finally {
            setIsSaving(false);
        }
    };

    /**
     * Remove a draft preview landing page.
     * Note: Published landing pages cannot be removed because DOIs are persistent
     * and must always resolve to a valid landing page.
     */
    const handleRemovePreview = async () => {
        if (!resource.id || !currentConfig) return;

        // Only allow removal of draft landing pages
        // Published landing pages cannot be depublished because DOIs are persistent
        if (currentConfig.status === 'published') {
            toast.error('Published landing pages cannot be removed. DOIs must always resolve to a valid landing page.');
            return;
        }

        if (!confirm('Are you sure you want to remove this landing page preview?')) {
            return;
        }

        setIsSaving(true);

        try {
            await axios.delete(`/resources/${resource.id}/landing-page`);
            setCurrentConfig(null);
            setPreviewUrl('');
            toast.success('Landing page preview removed successfully');
            onSuccess?.();
            onClose();
        } catch (error) {
            console.error('Failed to remove preview:', error);
            toast.error('Failed to remove landing page preview');
        } finally {
            setIsSaving(false);
        }
    };

    /**
     * Track whether the user has made unsaved changes to the configuration.
     * Used to determine if session-based preview should be used and to show visual feedback.
     */
    const hasUnsavedChanges = useMemo(() => {
        if (!currentConfig) return false;
        return template !== currentConfig.template || ftpUrl !== (currentConfig.ftp_url ?? '');
    }, [currentConfig, template, ftpUrl]);

    const copyToClipboard = async (text: string, label: string) => {
        try {
            // Ensure we copy a full URL (with origin) for sharing.
            // If the URL is already absolute (starts with http), use as-is.
            // Otherwise, prepend the current origin to make it shareable.
            const fullUrl = text.startsWith('http') ? text : `${window.location.origin}${text}`;
            await navigator.clipboard.writeText(fullUrl);
            toast.success(`${label} copied to clipboard`);
        } catch (error) {
            console.error('Failed to copy:', error);
            toast.error(`Failed to copy ${label.toLowerCase()}`);
        }
    };

    /**
     * Open a preview of the landing page.
     *
     * If the user has made unsaved changes (template or FTP URL), we use the
     * session-based preview to show the new configuration before saving.
     * Otherwise, we open the existing saved preview/public URL.
     */
    const openPreview = async () => {
        // If there are unsaved changes or no saved config, use session-based preview
        // This allows users to preview template changes before saving
        if (hasUnsavedChanges || !currentConfig) {
            if (!resource.id) {
                toast.error('Unable to generate preview');
                return;
            }

            try {
                const payload = {
                    template,
                    ftp_url: ftpUrl || null,
                };

                // Store preview in session and get preview URL
                const response = await axios.post<{ preview_url: string }>(`/resources/${resource.id}/landing-page/preview`, payload);

                // Open preview in new tab
                const previewUrlFromServer = response.data?.preview_url;
                const fallbackPreviewUrl = `/resources/${resource.id}/landing-page/preview`;
                window.open(previewUrlFromServer || fallbackPreviewUrl, '_blank');
            } catch (error) {
                console.error('Failed to create preview:', error);

                let errorMessage = 'Failed to create preview';
                if (isAxiosError(error) && error.response?.data?.message) {
                    errorMessage = error.response.data.message;
                }

                toast.error(errorMessage);
            }
            return;
        }

        // No unsaved changes - use existing saved preview URL (with token)
        // Always use preview URL for the Preview button, even if published,
        // to maintain consistency and distinguish from the Public URL copy action
        if (previewUrl) {
            window.open(previewUrl, '_blank');
            return;
        }

        // Fallback to public URL if no preview URL is available
        if (currentConfig.status === 'published' && currentConfig.public_url) {
            window.open(currentConfig.public_url, '_blank');
            return;
        }

        toast.error('Unable to generate preview');
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Globe className="size-5" />
                        Setup Landing Page
                    </DialogTitle>
                    <DialogDescription>
                        Configure the public landing page for <span className="font-medium">{resource.title ?? `Resource #${resource.id}`}</span>
                    </DialogDescription>
                </DialogHeader>

                {isLoading ? (
                    <div className="py-8 text-center text-muted-foreground">Loading configuration...</div>
                ) : (
                    <div className="space-y-6 py-4">
                        {/* Template Selection */}
                        <div className="space-y-2">
                            <Label htmlFor="template">Landing Page Template</Label>
                            <Select value={template} onValueChange={setTemplate}>
                                <SelectTrigger id="template">
                                    <SelectValue placeholder="Select a template" />
                                </SelectTrigger>
                                <SelectContent>
                                    {getTemplateOptions().map((tmpl) => (
                                        <SelectItem key={tmpl.value} value={tmpl.value}>
                                            <div className="flex flex-col">
                                                <span>{tmpl.label}</span>
                                                <span className="text-xs text-muted-foreground">{tmpl.description}</span>
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-sm text-muted-foreground">Choose the design template for your landing page</p>
                        </div>

                        {/* FTP URL */}
                        <div className="space-y-2">
                            <Label htmlFor="ftp-url">Download URL (FTP)</Label>
                            <Input
                                id="ftp-url"
                                type="url"
                                placeholder="https://datapub.gfz-potsdam.de/download/..."
                                value={ftpUrl}
                                onChange={(e) => setFtpUrl(e.target.value)}
                            />
                            <p className="text-sm text-muted-foreground">Direct link to download the primary data files</p>
                        </div>

                        {/* Unsaved Changes Warning */}
                        {hasUnsavedChanges && (
                            <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950/30 dark:text-yellow-200">
                                You have unsaved changes. Preview will show the new configuration.
                            </div>
                        )}

                        {/* Status Toggle */}
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="published" className="text-base">
                                    Publish Landing Page
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    {currentConfig?.status === 'published'
                                        ? 'This landing page is published. DOIs are persistent and must always resolve to a valid landing page.'
                                        : 'Make this landing page publicly accessible'}
                                </p>
                            </div>
                            <Switch
                                id="published"
                                checked={isPublished}
                                onCheckedChange={setIsPublished}
                                disabled={currentConfig?.status === 'published'}
                            />
                        </div>

                        {/* Preview URL (if draft exists) */}
                        {currentConfig && currentConfig.status === 'draft' && previewUrl && (
                            <div className="space-y-2 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                                <Label className="text-blue-900 dark:text-blue-100">Preview URL (Draft Mode)</Label>
                                <div className="flex gap-2">
                                    <Input readOnly value={previewUrl} className="bg-white font-mono text-xs dark:bg-gray-950" />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        onClick={() => copyToClipboard(previewUrl, 'Preview URL')}
                                        title="Copy preview URL"
                                    >
                                        <Copy className="size-4" />
                                    </Button>
                                </div>
                                <p className="text-xs text-blue-700 dark:text-blue-300">
                                    Share this URL with authors for review (requires preview token)
                                </p>
                            </div>
                        )}

                        {/* Public URL (only if actually published in database) */}
                        {currentConfig && currentConfig.status === 'published' && currentConfig.public_url && (
                            <div className="space-y-2 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/30">
                                <Label className="text-green-900 dark:text-green-100">Public URL</Label>
                                <div className="flex gap-2">
                                    <Input readOnly value={currentConfig.public_url} className="bg-white font-mono text-xs dark:bg-gray-950" />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        onClick={() => copyToClipboard(currentConfig.public_url, 'Public URL')}
                                        title="Copy public URL"
                                    >
                                        <Copy className="size-4" />
                                    </Button>
                                </div>
                                <p className="text-xs text-green-700 dark:text-green-300">This landing page is publicly accessible</p>
                            </div>
                        )}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    {/* Only show Remove Preview for draft landing pages.
                        Published landing pages cannot be removed because DOIs are persistent. */}
                    {currentConfig && currentConfig.status === 'draft' && (
                        <Button type="button" variant="destructive" onClick={handleRemovePreview} disabled={isSaving} className="mr-auto">
                            Remove Preview
                        </Button>
                    )}

                    <Button type="button" variant="outline" onClick={openPreview} disabled={isLoading}>
                        <Eye className="mr-2 size-4" />
                        Preview
                    </Button>

                    <Button type="button" variant="secondary" onClick={onClose} disabled={isSaving}>
                        Cancel
                    </Button>

                    <Button type="button" onClick={handleSave} disabled={isSaving || isLoading}>
                        {isSaving
                            ? 'Saving...'
                            : currentConfig
                              ? currentConfig.status === 'draft' && isPublished
                                  ? 'Publish'
                                  : 'Update'
                              : isPublished
                                ? 'Create & Publish'
                                : 'Create Preview'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
