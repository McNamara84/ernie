import { router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { Copy, Eye, Globe } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { withBasePath } from '@/lib/base-path';
import { LANDING_PAGE_TEMPLATES,type LandingPageConfig } from '@/types/landing-page';

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
    existingConfig?: LandingPageConfig | null;
}

export default function SetupLandingPageModal({
    resource,
    isOpen,
    onClose,
    existingConfig,
}: SetupLandingPageModalProps) {
    const [template, setTemplate] = useState<string>(existingConfig?.template ?? 'default_gfz');
    const [ftpUrl, setFtpUrl] = useState<string>(existingConfig?.ftp_url ?? '');
    const [isPublished, setIsPublished] = useState<boolean>(
        (existingConfig?.status ?? 'draft') === 'published'
    );
    const [previewUrl, setPreviewUrl] = useState<string>(existingConfig?.preview_url ?? '');
    const [isLoading, setIsLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);

    // Load existing config when modal opens
    useEffect(() => {
        if (isOpen && resource.id) {
            if (!existingConfig) {
                loadLandingPageConfig();
            }
        }
    }, [isOpen, resource.id]);

    const loadLandingPageConfig = async () => {
        setIsLoading(true);
        try {
            const response = await axios.get<{ landing_page: LandingPageConfig }>(
                withBasePath(`/resources/${resource.id}/landing-page`)
            );
            const config = response.data.landing_page;
            setTemplate(config.template);
            setFtpUrl(config.ftp_url ?? '');
            setIsPublished(config.status === 'published');
            setPreviewUrl(config.preview_url);
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) {
                // No landing page exists yet, use defaults
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

            const url = withBasePath(`/resources/${resource.id}/landing-page`);
            const response = existingConfig
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

            if (!existingConfig) {
                const newPreviewUrl =
                    'preview_url' in response.data
                        ? String(response.data.preview_url ?? '')
                        : '';
                setPreviewUrl(newPreviewUrl);
            } else {
                const updatedPreviewUrl =
                    'landing_page' in response.data &&
                    response.data.landing_page &&
                    'preview_url' in response.data.landing_page
                        ? String(response.data.landing_page.preview_url ?? '')
                        : '';
                setPreviewUrl(updatedPreviewUrl);
            }

            // Reload page to update UI
            router.reload({ only: ['resources'] });
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

    const handleDelete = async () => {
        if (!resource.id || !existingConfig) return;

        if (!confirm('Are you sure you want to delete this landing page configuration?')) {
            return;
        }

        setIsSaving(true);

        try {
            await axios.delete(withBasePath(`/resources/${resource.id}/landing-page`));
            toast.success('Landing page deleted successfully');
            onClose();
            router.reload({ only: ['resources'] });
        } catch (error) {
            console.error('Failed to delete landing page:', error);
            toast.error('Failed to delete landing page');
        } finally {
            setIsSaving(false);
        }
    };

    const copyToClipboard = async (text: string, label: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(`${label} copied to clipboard`);
        } catch (error) {
            console.error('Failed to copy:', error);
            toast.error(`Failed to copy ${label.toLowerCase()}`);
        }
    };

    const openPreview = () => {
        if (previewUrl) {
            window.open(previewUrl, '_blank');
        } else if (isPublished && resource.id) {
            window.open(withBasePath(`/datasets/${resource.id}`), '_blank');
        } else {
            toast.error('Please save the landing page first to generate a preview');
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Globe className="size-5" />
                        Setup Landing Page
                    </DialogTitle>
                    <DialogDescription>
                        Configure the public landing page for{' '}
                        <span className="font-medium">
                            {resource.title ?? `Resource #${resource.id}`}
                        </span>
                    </DialogDescription>
                </DialogHeader>

                {isLoading ? (
                    <div className="py-8 text-center text-muted-foreground">
                        Loading configuration...
                    </div>
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
                                    {LANDING_PAGE_TEMPLATES.map((tmpl) => (
                                        <SelectItem key={tmpl.value} value={tmpl.value}>
                                            <div className="flex flex-col">
                                                <span>{tmpl.label}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {tmpl.description}
                                                </span>
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-sm text-muted-foreground">
                                Choose the design template for your landing page
                            </p>
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
                            <p className="text-sm text-muted-foreground">
                                Direct link to download the primary data files
                            </p>
                        </div>

                        {/* Status Toggle */}
                        <div className="flex items-center justify-between rounded-lg border p-4">
                            <div className="space-y-0.5">
                                <Label htmlFor="published" className="text-base">
                                    Publish Landing Page
                                </Label>
                                <p className="text-sm text-muted-foreground">
                                    Make this landing page publicly accessible
                                </p>
                            </div>
                            <Switch
                                id="published"
                                checked={isPublished}
                                onCheckedChange={setIsPublished}
                            />
                        </div>

                        {/* Preview URL (if exists) */}
                        {previewUrl && (
                            <div className="space-y-2 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                                <Label className="text-blue-900 dark:text-blue-100">
                                    Preview URL
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        readOnly
                                        value={previewUrl}
                                        className="font-mono text-xs bg-white dark:bg-gray-950"
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        onClick={() =>
                                            copyToClipboard(previewUrl, 'Preview URL')
                                        }
                                        title="Copy preview URL"
                                    >
                                        <Copy className="size-4" />
                                    </Button>
                                </div>
                                <p className="text-xs text-blue-700 dark:text-blue-300">
                                    {isPublished
                                        ? 'This is the public URL for your landing page'
                                        : 'Share this URL with authors for review (works in draft mode)'}
                                </p>
                            </div>
                        )}

                        {/* Public URL (if published) */}
                        {isPublished && resource.id && (
                            <div className="space-y-2 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/30">
                                <Label className="text-green-900 dark:text-green-100">
                                    Public URL
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        readOnly
                                        value={withBasePath(`/datasets/${resource.id}`)}
                                        className="font-mono text-xs bg-white dark:bg-gray-950"
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="outline"
                                        onClick={() =>
                                            copyToClipboard(
                                                window.location.origin +
                                                    withBasePath(`/datasets/${resource.id}`),
                                                'Public URL'
                                            )
                                        }
                                        title="Copy public URL"
                                    >
                                        <Copy className="size-4" />
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    {existingConfig && (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={isSaving}
                            className="mr-auto"
                        >
                            Delete
                        </Button>
                    )}

                    <Button
                        type="button"
                        variant="outline"
                        onClick={openPreview}
                        disabled={isLoading}
                    >
                        <Eye className="mr-2 size-4" />
                        Preview
                    </Button>

                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onClose}
                        disabled={isSaving}
                    >
                        Cancel
                    </Button>

                    <Button type="button" onClick={handleSave} disabled={isSaving || isLoading}>
                        {isSaving
                            ? 'Saving...'
                            : existingConfig
                              ? 'Update'
                              : 'Create & Activate'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
