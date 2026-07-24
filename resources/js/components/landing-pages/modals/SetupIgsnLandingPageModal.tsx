import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { Copy, Eye, FlaskConical } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { ExternalLandingPageFields } from '@/components/landing-pages/modals/ExternalLandingPageFields';
import {
    buildLandingPagePreviewPayload,
    buildLandingPageSetupPayload,
    getHydratedLandingPageTemplateId,
    getLandingPageRequestErrorMessage,
    getPreferredIgsnTemplate,
    getPreviewableExternalUrl,
    isLandingPageNotFoundError,
} from '@/components/landing-pages/modals/landing-page-modal-helpers';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { User as AuthUser } from '@/types';
import {
    getDefaultIgsnTemplate,
    getIgsnTemplateOptions,
    type LandingPageConfig,
    type LandingPageDomain,
    type LandingPageTemplateSummary,
} from '@/types/landing-page';

interface IgsnResource {
    id: number;
    doi?: string | null;
    title?: string;
    [key: string]: unknown;
}

interface IgsnTemplateOptions {
    datacenter: { id: number; name: string } | null;
    datacenter_template: { id: number; name: string; slug: string } | null;
    system_default: { id: number; name: string; slug: string };
    automatic_template: { id: number; name: string; slug: string };
    automatic_source: 'datacenter' | 'default';
    supports_datacenter_inheritance: boolean;
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
    const { auth } = usePage<{ auth: { user: AuthUser | null } }>().props;
    const canDeleteLandingPages = auth.user?.can_delete_landing_pages ?? false;

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
    const [templateInheritance, setTemplateInheritance] = useState<IgsnTemplateOptions | null>(null);
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
            loadTemplateInheritance();
        } else if (!isOpen) {
            // Reset state when modal closes
            setCurrentConfig(null);
            setTemplate(getDefaultIgsnTemplate());
            setIsPublished(false);
            setPreviewUrl('');
            setExternalDomainId('');
            setExternalPath('');
            setLandingPageTemplateId(null);
            setTemplateInheritance(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [applyConfigState, existingConfig, isOpen, resource.id]);

    const loadAvailableDomains = async () => {
        try {
            const response = await axios.get<{ domains: LandingPageDomain[] }>('/api/landing-page-domains/list');
            setAvailableDomains(response.data.domains ?? []);
        } catch (error) {
            if (isLandingPageNotFoundError(error)) {
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
            if (isLandingPageNotFoundError(error)) {
                setCustomTemplates([]);
                return;
            }

            console.error('Failed to load custom templates:', error);
        }
    };

    const loadTemplateInheritance = async () => {
        try {
            const response = await axios.get<IgsnTemplateOptions>(`/resources/${resource.id}/landing-page/template-options`);
            setTemplateInheritance(response.data);
        } catch (error) {
            setTemplateInheritance(null);
            console.error('Failed to load IGSN template inheritance context:', error);
        }
    };

    const loadLandingPageConfig = async () => {
        setIsLoading(true);
        try {
            const response = await axios.get<{ landing_page: LandingPageConfig }>(`/resources/${resource.id}/landing-page`);
            applyConfigState(response.data.landing_page);
        } catch (error) {
            if (isLandingPageNotFoundError(error)) {
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
            const payload = buildLandingPageSetupPayload({
                template,
                landingPageTemplateId,
                isPublished,
                supportsFtpUrl: false,
                supportsLinks: false,
                isExternal,
                externalDomainId,
                externalPath,
            });

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
            toast.error(getLandingPageRequestErrorMessage(error, 'Failed to save landing page configuration'));
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
        const currentTemplate = getPreferredIgsnTemplate(currentConfig.template);
        const currentLandingPageTemplateId = getHydratedLandingPageTemplateId(currentTemplate, currentConfig);
        const baseChanges =
            template !== currentTemplate ||
            isPublished !== (currentConfig.status === 'published') ||
            landingPageTemplateId !== currentLandingPageTemplateId;

        if (isExternal) {
            return (
                baseChanges ||
                externalDomainId !== String(currentConfig.external_domain_id ?? '') ||
                externalPath !== (currentConfig.external_path ?? '')
            );
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
        return getPreviewableExternalUrl({
            availableDomains,
            externalDomainId,
            externalPath,
            isExternal,
        });
    }, [availableDomains, externalDomainId, externalPath, isExternal]);

    /**
     * Open a preview of the landing page.
     */
    const openPreview = async () => {
        if (isExternal) {
            if (computedExternalUrl) {
                window.open(computedExternalUrl, '_blank', 'noopener,noreferrer');
            } else if (!hasUnsavedChanges && currentConfig?.external_url) {
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
                const payload = buildLandingPagePreviewPayload({
                    template,
                    landingPageTemplateId,
                    supportsFtpUrl: false,
                    supportsLinks: false,
                    isExternal,
                    externalDomainId,
                    externalPath,
                });

                const response = await axios.post<{ preview_url: string }>(`/resources/${resource.id}/landing-page/preview`, payload);

                const previewUrlFromServer = response.data?.preview_url;
                const fallbackPreviewUrl = `/resources/${resource.id}/landing-page/preview`;
                window.open(previewUrlFromServer || fallbackPreviewUrl, '_blank', 'noopener,noreferrer');
            } catch (error) {
                console.error('Failed to create preview:', error);
                toast.error(getLandingPageRequestErrorMessage(error, 'Failed to create preview'));
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
    const automaticTemplateDescription =
        templateInheritance?.automatic_source === 'datacenter'
            ? `Datacenter template: ${templateInheritance.automatic_template.name}`
            : `System default: ${templateInheritance?.system_default?.name ?? 'Default GFZ IGSN'}`;

    const displayTitle = resource.title ?? `IGSN #${resource.id}`;

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent data-testid="setup-igsn-lp-modal-content" className="flex max-h-[90vh] max-w-2xl flex-col gap-0 overflow-hidden p-0">
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
                                        className="mt-1 line-clamp-2 block rounded-sm font-medium wrap-break-word text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                    >
                                        {displayTitle}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    data-testid="setup-igsn-lp-modal-resource-title-tooltip"
                                    side="bottom"
                                    className="max-w-[min(32rem,calc(100vw-2rem))] text-left wrap-break-word whitespace-normal"
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
                        className="min-h-0 flex-1 overflow-y-auto px-6 py-8 text-center text-muted-foreground"
                    >
                        Loading configuration...
                    </div>
                ) : (
                    <div data-testid="setup-igsn-lp-modal-scroll-area" className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
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
                                <p className="text-sm text-muted-foreground">
                                    Choose the design template for your IGSN landing page. Automatic layout: {automaticTemplateDescription}.
                                </p>
                            </div>

                            {isExternal && (
                                <ExternalLandingPageFields
                                    availableDomains={availableDomains}
                                    externalDomainId={externalDomainId}
                                    onExternalDomainIdChange={setExternalDomainId}
                                    externalPath={externalPath}
                                    onExternalPathChange={setExternalPath}
                                    computedExternalUrl={computedExternalUrl}
                                    pathExample="/sample/12345"
                                />
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

                <DialogFooter data-testid="setup-igsn-lp-modal-footer" className="shrink-0 flex-wrap gap-2 border-t px-6 py-4">
                    {/* Only show Remove Preview for draft landing pages */}
                    {canDeleteLandingPages && currentConfig && currentConfig.status === 'draft' && (
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
