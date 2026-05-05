import axios, { isAxiosError } from 'axios';
import { Copy, ExternalLink, Eye, FlaskConical } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getDefaultIgsnTemplate, getIgsnTemplateOptions, type LandingPageConfig, type LandingPageDomain, type LandingPageTemplateSummary } from '@/types/landing-page';

const IGSN_TEMPLATE_KEYS = new Set(getIgsnTemplateOptions().map((option) => option.value));

function getPreferredIgsnTemplate(template?: string | null): string {
    if (template && IGSN_TEMPLATE_KEYS.has(template)) {
        return template;
    }

    return getDefaultIgsnTemplate();
}

function templateSupportsCustomTemplateId(template: string): boolean {
    return template === getDefaultIgsnTemplate();
}

function getHydratedLandingPageTemplateId(template: string, config?: LandingPageConfig | null): number | null {
    if (!config || !templateSupportsCustomTemplateId(template)) {
        return null;
    }

    if (config.landing_page_template?.template_type === 'resource') {
        return null;
    }

    return config.landing_page_template_id ?? null;
}

function getPayloadLandingPageTemplateId(template: string, landingPageTemplateId?: number | null): number | null {
    return templateSupportsCustomTemplateId(template) ? (landingPageTemplateId ?? null) : null;
}

interface IgsnResource {
    id: number;
    doi?: string | null;
    title?: string;
    [key: string]: unknown;
}

interface SetupIgsnLandingPageModalProps {
    resource: IgsnResource;
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    existingConfig?: LandingPageConfig | null;
}

/**
 * Modal for setting up landing pages for IGSN (Physical Sample) resources.
 *
 * This is a simplified version of SetupLandingPageModal, specifically designed for IGSNs:
 * - No FTP URL field (not applicable to physical samples)
 * - Supports IGSN renderers plus the shared external redirect template
 * - Uses FlaskConical icon instead of Globe
 */
export default function SetupIgsnLandingPageModal({ resource, isOpen, onClose, onSuccess, existingConfig }: SetupIgsnLandingPageModalProps) {
    const initialTemplate = getPreferredIgsnTemplate(existingConfig?.template);

    const [template, setTemplate] = useState<string>(initialTemplate);
    const [isPublished, setIsPublished] = useState<boolean>((existingConfig?.status ?? 'draft') === 'published');
    const [previewUrl, setPreviewUrl] = useState<string>(existingConfig?.preview_url ?? '');
    const [isLoading, setIsLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [currentConfig, setCurrentConfig] = useState<LandingPageConfig | null>(existingConfig ?? null);
    const [externalDomainId, setExternalDomainId] = useState<string>(String(existingConfig?.external_domain_id ?? ''));
    const [externalPath, setExternalPath] = useState<string>(existingConfig?.external_path ?? '');
    const [availableDomains, setAvailableDomains] = useState<LandingPageDomain[]>([]);
    const [customTemplates, setCustomTemplates] = useState<LandingPageTemplateSummary[]>([]);
    const [landingPageTemplateId, setLandingPageTemplateId] = useState<number | null>(
        getHydratedLandingPageTemplateId(initialTemplate, existingConfig),
    );

    const isExternal = template === 'external';

    const eligibleCustomTemplates = useMemo(
        () => customTemplates.filter((customTemplate) => !customTemplate.is_default && customTemplate.template_type === 'igsn'),
        [customTemplates],
    );

    const applyConfigState = useCallback((config: LandingPageConfig) => {
        const preferredTemplate = getPreferredIgsnTemplate(config.template);

        setCurrentConfig(config);
        setTemplate(preferredTemplate);
        setIsPublished(config.status === 'published');
        setPreviewUrl(config.preview_url ?? '');
        setExternalDomainId(String(config.external_domain_id ?? ''));
        setExternalPath(config.external_path ?? '');
        setLandingPageTemplateId(getHydratedLandingPageTemplateId(preferredTemplate, config));
    }, []);

    // Load existing config when modal opens
    useEffect(() => {
        if (isOpen && resource.id) {
            if (existingConfig) {
                // Use existing config passed as prop
                applyConfigState(existingConfig);
            } else {
                // Load from server
                loadLandingPageConfig();
            }
            loadAvailableDomains();
            loadCustomTemplates();
        } else if (!isOpen) {
            // Reset state when modal closes
            setCurrentConfig(null);
            setTemplate(getDefaultIgsnTemplate());
            setIsPublished(false);
            setPreviewUrl('');
            setExternalDomainId('');
            setExternalPath('');
            setLandingPageTemplateId(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [applyConfigState, existingConfig, isOpen, resource.id]);

    const loadAvailableDomains = async () => {
        try {
            const response = await axios.get<{ domains: LandingPageDomain[] }>('/api/landing-page-domains/list');
            setAvailableDomains(response.data.domains ?? []);
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) {
                setAvailableDomains([]);
                return;
            }

            console.error('Failed to load landing page domains:', error);
        }
    };

    const loadCustomTemplates = async () => {
        try {
            const response = await axios.get<{ templates: LandingPageTemplateSummary[] }>('/api/landing-page-templates');
            setCustomTemplates(response.data.templates ?? []);
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) {
                setCustomTemplates([]);
                return;
            }

            console.error('Failed to load custom templates:', error);
        }
    };

    const loadLandingPageConfig = async () => {
        setIsLoading(true);
        try {
            const response = await axios.get<{ landing_page: LandingPageConfig }>(`/resources/${resource.id}/landing-page`);
            applyConfigState(response.data.landing_page);
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) {
                // No landing page exists yet, use defaults
                setCurrentConfig(null);
                setTemplate(getDefaultIgsnTemplate());
                setIsPublished(false);
                setPreviewUrl('');
                setExternalDomainId('');
                setExternalPath('');
                setLandingPageTemplateId(null);
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
            // Note: No ftp_url for IGSN landing pages
            const payload: Record<string, string | number | null> = {
                template,
                landing_page_template_id: getPayloadLandingPageTemplateId(template, landingPageTemplateId),
                status: isPublished ? 'published' : 'draft',
            };

            if (isExternal) {
                payload.external_domain_id = externalDomainId ? Number(externalDomainId) : null;
                payload.external_path = externalPath || null;
            }

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
                applyConfigState(response.data.landing_page);
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
     * Note: No ftpUrl comparison for IGSN landing pages.
     */
    const hasUnsavedChanges = useMemo(() => {
        if (!currentConfig) return false;
        const baseChanges = template !== currentConfig.template
            || isPublished !== (currentConfig.status === 'published')
            || landingPageTemplateId !== (currentConfig.landing_page_template_id ?? null);

        if (isExternal) {
            return baseChanges
                || externalDomainId !== String(currentConfig.external_domain_id ?? '')
                || externalPath !== (currentConfig.external_path ?? '');
        }

        return baseChanges;
    }, [currentConfig, externalDomainId, externalPath, isExternal, landingPageTemplateId, template, isPublished]);

    const copyToClipboard = async (text: string, label: string) => {
        try {
            const fullUrl = text.startsWith('http') ? text : `${window.location.origin}${text}`;
            await navigator.clipboard.writeText(fullUrl);
            toast.success(`${label} copied to clipboard`);
        } catch (error) {
            console.error('Failed to copy:', error);
            toast.error(`Failed to copy ${label.toLowerCase()}`);
        }
    };

    const computedExternalUrl = useMemo(() => {
        if (!isExternal || !externalDomainId) return null;

        const domain = availableDomains.find((availableDomain) => availableDomain.id === Number(externalDomainId));

        if (!domain) return null;

        return domain.domain + (externalPath || '').replace(/^\/+/, '');
    }, [availableDomains, externalDomainId, externalPath, isExternal]);

    /**
     * Open a preview of the landing page.
     */
    const openPreview = async () => {
        if (isExternal) {
            if (computedExternalUrl) {
                window.open(computedExternalUrl, '_blank', 'noopener,noreferrer');
            } else if (currentConfig?.external_url) {
                window.open(currentConfig.external_url, '_blank', 'noopener,noreferrer');
            } else {
                toast.error('Please select a domain and enter a path to preview the external URL.');
            }

            return;
        }

        // If there are unsaved changes or no saved config, use session-based preview
        if (hasUnsavedChanges || !currentConfig) {
            if (!resource.id) {
                toast.error('Unable to generate preview');
                return;
            }

            try {
                // Note: No ftp_url for IGSN landing pages
                const payload = {
                    template,
                    landing_page_template_id: getPayloadLandingPageTemplateId(template, landingPageTemplateId),
                };

                const response = await axios.post<{ preview_url: string }>(`/resources/${resource.id}/landing-page/preview`, payload);

                const previewUrlFromServer = response.data?.preview_url;
                const fallbackPreviewUrl = `/resources/${resource.id}/landing-page/preview`;
                window.open(previewUrlFromServer || fallbackPreviewUrl, '_blank', 'noopener,noreferrer');
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

        // No unsaved changes - use existing saved preview URL
        if (previewUrl) {
            window.open(previewUrl, '_blank', 'noopener,noreferrer');
            return;
        }

        // Fallback to public URL if no preview URL is available
        if (currentConfig.status === 'published' && currentConfig.public_url) {
            window.open(currentConfig.public_url, '_blank', 'noopener,noreferrer');
            return;
        }

        toast.error('Unable to generate preview');
    };

    // Get IGSN-specific template options
    const templateOptions = getIgsnTemplateOptions();

    const displayTitle = resource.title ?? `IGSN #${resource.id}`;

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent
                data-testid="setup-igsn-lp-modal-content"
                className="flex max-h-[90vh] max-w-2xl flex-col gap-0 overflow-hidden p-0"
            >
                <DialogHeader className="shrink-0 border-b px-6 pt-6 pb-4">
                    <DialogTitle className="flex items-center gap-2">
                        <FlaskConical className="size-5" />
                        Setup IGSN Landing Page
                    </DialogTitle>
                    <DialogDescription>
                        Configure the public landing page for physical sample:
                        {/* delayDuration aligned with the global TooltipProvider in
                            app-sidebar-layout.tsx to keep tooltip timing consistent
                            across the UI. The local provider is kept so the modal is
                            self-contained when rendered in tests or outside the app shell. */}
                        <TooltipProvider delayDuration={0}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span
                                        data-testid="setup-igsn-lp-modal-resource-title"
                                        tabIndex={0}
                                        className="mt-1 block line-clamp-2 wrap-break-word rounded-sm font-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                    >
                                        {displayTitle}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    data-testid="setup-igsn-lp-modal-resource-title-tooltip"
                                    side="bottom"
                                    className="max-w-[min(32rem,calc(100vw-2rem))] wrap-break-word whitespace-normal text-left"
                                >
                                    {displayTitle}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </DialogDescription>
                </DialogHeader>

                {isLoading ? (
                    <div
                        data-testid="setup-igsn-lp-modal-scroll-area"
                        className="flex-1 min-h-0 overflow-y-auto px-6 py-8 text-center text-muted-foreground"
                    >
                        Loading configuration...
                    </div>
                ) : (
                    <div data-testid="setup-igsn-lp-modal-scroll-area" className="flex-1 min-h-0 overflow-y-auto px-6 py-4">
                        <div className="space-y-6">
                        {/* Template Selection */}
                        <div className="space-y-2">
                            <Label htmlFor="template">Landing Page Template</Label>
                            <Select
                                value={landingPageTemplateId ? `custom:${landingPageTemplateId}` : template}
                                onValueChange={(value) => {
                                    if (value.startsWith('custom:')) {
                                        const id = Number.parseInt(value.replace('custom:', ''), 10);

                                        if (!Number.isNaN(id)) {
                                            setTemplate(getDefaultIgsnTemplate());
                                            setLandingPageTemplateId(id);
                                        }

                                        return;
                                    }

                                    setTemplate(value);
                                    setLandingPageTemplateId(null);
                                }}
                            >
                                <SelectTrigger id="template">
                                    <SelectValue placeholder="Select a template" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Built-in Templates</SelectLabel>
                                        {templateOptions.map((tmpl) => (
                                            <SelectItem key={tmpl.value} value={tmpl.value}>
                                                <div className="flex flex-col">
                                                    <span>{tmpl.label}</span>
                                                    <span className="text-xs text-muted-foreground">{tmpl.description}</span>
                                                </div>
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>

                                    {eligibleCustomTemplates.length > 0 && (
                                        <>
                                            <SelectSeparator />
                                            <SelectGroup>
                                                <SelectLabel>Custom Templates</SelectLabel>
                                                {eligibleCustomTemplates.map((customTemplate) => (
                                                    <SelectItem key={customTemplate.id} value={`custom:${customTemplate.id}`}>
                                                        <div className="flex flex-col">
                                                            <span>{customTemplate.name}</span>
                                                            <span className="text-xs text-muted-foreground">Custom IGSN template</span>
                                                        </div>
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </>
                                    )}
                                </SelectContent>
                            </Select>
                            <p className="text-sm text-muted-foreground">Choose the design template for your IGSN landing page</p>
                        </div>

                        {isExternal && (
                            <div className="space-y-4 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-800 dark:bg-blue-950/20">
                                <div className="flex items-center gap-2 text-sm font-medium text-blue-900 dark:text-blue-100">
                                    <ExternalLink className="size-4" />
                                    External URL Configuration
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="external-domain">Domain</Label>
                                    <Select value={externalDomainId} onValueChange={setExternalDomainId}>
                                        <SelectTrigger id="external-domain">
                                            <SelectValue placeholder="Select a domain" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableDomains.map((domain) => (
                                                <SelectItem key={domain.id} value={String(domain.id)}>
                                                    {domain.domain}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {availableDomains.length === 0 && (
                                        <p className="text-xs text-amber-600 dark:text-amber-400">
                                            No domains configured. An administrator can add domains in Editor Settings.
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="external-path">Path</Label>
                                    <Input
                                        id="external-path"
                                        type="text"
                                        placeholder="/path/to/landing-page"
                                        value={externalPath}
                                        onChange={(event) => setExternalPath(event.target.value)}
                                    />
                                    <p className="text-sm text-muted-foreground">Path appended to the domain (e.g. /sample/12345)</p>
                                </div>

                                {externalDomainId && (
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">Resulting URL</Label>
                                        <p className="break-all rounded bg-white/80 px-2 py-1 font-mono text-xs text-blue-800 dark:bg-gray-900/50 dark:text-blue-200">
                                            {(availableDomains.find((domain) => domain.id === Number(externalDomainId))?.domain ?? '') +
                                                (externalPath || '').replace(/^\/+/, '')}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* No FTP URL field for IGSN landing pages */}

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
                    </div>
                )}

                <DialogFooter
                    data-testid="setup-igsn-lp-modal-footer"
                    className="shrink-0 flex-wrap gap-2 border-t px-6 py-4"
                >
                    {/* Only show Remove Preview for draft landing pages */}
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
