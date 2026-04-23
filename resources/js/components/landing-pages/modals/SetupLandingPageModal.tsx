import type { DragEndEvent } from '@dnd-kit/core';
import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import axios, { isAxiosError } from 'axios';
import { Copy, ExternalLink, Eye, Globe, GripVertical, Plus, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getDefaultTemplate, getTemplateOptions, type LandingPageConfig, type LandingPageDomain, type LandingPageLink, type LandingPageTemplateSummary } from '@/types/landing-page';

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

function SortableLinkItem({
    link,
    index,
    onRemove,
    onUpdate,
}: {
    link: LandingPageLink;
    index: number;
    onRemove: (index: number) => void;
    onUpdate: (index: number, field: 'url' | 'label', value: string) => void;
}) {
    const sortableId = link.id ?? link._clientId ?? `new-${link.position}`;
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: sortableId });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    return (
        <div ref={setNodeRef} style={style} className="flex items-start gap-2 rounded-md border bg-background p-2">
            <button
                type="button"
                aria-label="Reorder link"
                className="mt-2 cursor-grab touch-none text-muted-foreground hover:text-foreground"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <Input
                    placeholder="Display text"
                    value={link.label}
                    onChange={(e) => onUpdate(index, 'label', e.target.value)}
                    className="h-8 text-sm"
                />
                <Input
                    type="url"
                    placeholder="https://..."
                    value={link.url}
                    onChange={(e) => onUpdate(index, 'url', e.target.value)}
                    className="h-8 text-sm"
                />
            </div>
            <Button type="button" variant="ghost" size="icon" aria-label="Remove link" className="mt-1 size-7 shrink-0" onClick={() => onRemove(index)}>
                <X className="size-3.5" />
            </Button>
        </div>
    );
}

export default function SetupLandingPageModal({ resource, isOpen, onClose, onSuccess, existingConfig }: SetupLandingPageModalProps) {
    const [template, setTemplate] = useState<string>(existingConfig?.template ?? getDefaultTemplate());
    const [ftpUrl, setFtpUrl] = useState<string>(existingConfig?.ftp_url ?? '');
    const [isPublished, setIsPublished] = useState<boolean>((existingConfig?.status ?? 'draft') === 'published');
    const [previewUrl, setPreviewUrl] = useState<string>(existingConfig?.preview_url ?? '');
    const [isLoading, setIsLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [currentConfig, setCurrentConfig] = useState<LandingPageConfig | null>(existingConfig ?? null);

    // External landing page state
    const [externalDomainId, setExternalDomainId] = useState<string>(String(existingConfig?.external_domain_id ?? ''));
    const [externalPath, setExternalPath] = useState<string>(existingConfig?.external_path ?? '');
    const [availableDomains, setAvailableDomains] = useState<LandingPageDomain[]>([]);

    // Custom templates state
    const [customTemplates, setCustomTemplates] = useState<LandingPageTemplateSummary[]>([]);

    // Landing page template ID (for custom templates)
    const [landingPageTemplateId, setLandingPageTemplateId] = useState<number | null>(existingConfig?.landing_page_template_id ?? null);

    // Additional links state
    const [links, setLinks] = useState<LandingPageLink[]>(existingConfig?.links ?? []);

    const isExternal = template === 'external';
    const isIgsn = template === 'default_gfz_igsn';
    const supportsLinks = !isExternal && !isIgsn;
    const MAX_LINKS = 10;

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
                setExternalDomainId(String(existingConfig.external_domain_id ?? ''));
                setExternalPath(existingConfig.external_path ?? '');
                setLinks(existingConfig.links ?? []);
                setLandingPageTemplateId(existingConfig.landing_page_template_id ?? null);
            } else {
                // Load from server
                loadLandingPageConfig();
            }
            // Load available domains for external landing pages
            loadAvailableDomains();
            // Load custom templates for the dropdown
            loadCustomTemplates();
        } else if (!isOpen) {
            // Reset state when modal closes
            setCurrentConfig(null);
            setTemplate(getDefaultTemplate());
            setFtpUrl('');
            setIsPublished(false);
            setPreviewUrl('');
            setExternalDomainId('');
            setExternalPath('');
            setLinks([]);
            setLandingPageTemplateId(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, resource.id]);

    const loadAvailableDomains = async () => {
        try {
            const response = await axios.get<{ domains: LandingPageDomain[] }>('/api/landing-page-domains/list');
            setAvailableDomains(response.data.domains);
        } catch (error) {
            console.error('Failed to load landing page domains:', error);
            // Non-critical: domains will be empty, select will show placeholder
        }
    };

    const loadCustomTemplates = async () => {
        try {
            const response = await axios.get<{ templates: LandingPageTemplateSummary[] }>('/api/landing-page-templates');
            setCustomTemplates(response.data.templates ?? []);
        } catch (error) {
            console.error('Failed to load custom templates:', error);
        }
    };

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
            setExternalDomainId(String(config.external_domain_id ?? ''));
            setExternalPath(config.external_path ?? '');
            setLinks(config.links ?? []);
            setLandingPageTemplateId(config.landing_page_template_id ?? null);
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 404) {
                // No landing page exists yet, use defaults
                setCurrentConfig(null);
                setTemplate('default_gfz');
                setFtpUrl('');
                setIsPublished(false);
                setPreviewUrl('');
                setExternalDomainId('');
                setExternalPath('');
                setLinks([]);
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
            const payload: Record<string, unknown> = {
                template,
                ftp_url: isExternal ? null : ftpUrl || null,
                status: isPublished ? 'published' : 'draft',
                landing_page_template_id: landingPageTemplateId,
            };

            // Add external fields when template is 'external'
            if (isExternal) {
                payload.external_domain_id = externalDomainId ? Number(externalDomainId) : null;
                payload.external_path = externalPath || null;
            }

            // Add additional links when template supports them (filter out incomplete rows)
            if (supportsLinks) {
                payload.links = links
                    .filter((link) => link.url.trim() !== '' && link.label.trim() !== '')
                    .map((link, index) => ({
                        url: link.url,
                        label: link.label,
                        position: index,
                    }));
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
                const updatedConfig = response.data.landing_page;
                setCurrentConfig(updatedConfig);
                setTemplate(updatedConfig.template ?? getDefaultTemplate());
                setFtpUrl(updatedConfig.ftp_url ?? '');
                setPreviewUrl(updatedConfig.preview_url ?? '');
                setIsPublished(updatedConfig.status === 'published');
                setExternalDomainId(String(updatedConfig.external_domain_id ?? ''));
                setExternalPath(updatedConfig.external_path ?? '');
                setLinks(updatedConfig.links ?? []);
                setLandingPageTemplateId(updatedConfig.landing_page_template_id ?? null);
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
        const isExternalTemplate = template === 'external';
        const baseChanges =
            template !== currentConfig.template ||
            // ftpUrl is irrelevant for external templates (backend forces it to null)
            (!isExternalTemplate && ftpUrl !== (currentConfig.ftp_url ?? '')) ||
            isPublished !== (currentConfig.status === 'published') ||
            landingPageTemplateId !== (currentConfig.landing_page_template_id ?? null);

        // Check if links have changed
        const currentLinks = currentConfig.links ?? [];
        const linksChanged =
            links.length !== currentLinks.length ||
            links.some((link, i) => {
                const original = currentLinks[i];
                return !original || link.url !== original.url || link.label !== original.label || link.position !== original.position;
            });

        if (isExternalTemplate) {
            return (
                baseChanges ||
                externalDomainId !== String(currentConfig.external_domain_id ?? '') ||
                externalPath !== (currentConfig.external_path ?? '')
            );
        }
        return baseChanges || linksChanged;
    }, [currentConfig, template, ftpUrl, isPublished, externalDomainId, externalPath, links, landingPageTemplateId]);

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
    /**
     * Compute the external URL from the current domain selection and path.
     */
    const computedExternalUrl = useMemo(() => {
        if (!isExternal || !externalDomainId) return null;
        const domain = availableDomains.find((d) => d.id === Number(externalDomainId));
        if (!domain) return null;
        return domain.domain + (externalPath || '').replace(/^\/+/, '');
    }, [isExternal, externalDomainId, externalPath, availableDomains]);

    const openPreview = async () => {
        // For external landing pages, open the external URL directly
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
        // This allows users to preview template changes before saving
        if (hasUnsavedChanges || !currentConfig) {
            if (!resource.id) {
                toast.error('Unable to generate preview');
                return;
            }

            try {
                const payload: Record<string, unknown> = {
                    template,
                    ftp_url: ftpUrl || null,
                };

                // Include complete links for templates that support them (filter out incomplete rows)
                if (supportsLinks) {
                    const completeLinks = links
                        .filter((link) => link.url.trim() !== '' && link.label.trim() !== '')
                        .map((link, index) => ({
                            url: link.url,
                            label: link.label,
                            position: index,
                        }));
                    if (completeLinks.length > 0) {
                        payload.links = completeLinks;
                    }
                }

                // Store preview in session and get preview URL
                const response = await axios.post<{ preview_url: string }>(`/resources/${resource.id}/landing-page/preview`, payload);

                // Open preview in new tab
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

        // No unsaved changes - use existing saved preview URL (with token)
        // Always use preview URL for the Preview button, even if published,
        // to maintain consistency and distinguish from the Public URL copy action
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

    // --- Additional Links management ---
    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;
            if (!over || active.id === over.id) return;

            setLinks((prev) => {
                const getSortableId = (l: LandingPageLink) => l.id ?? l._clientId ?? `new-${l.position}`;
                const oldIndex = prev.findIndex((l) => getSortableId(l) === active.id);
                const newIndex = prev.findIndex((l) => getSortableId(l) === over.id);
                if (oldIndex === -1 || newIndex === -1) return prev;
                const reordered = arrayMove(prev, oldIndex, newIndex);
                return reordered.map((link, i) => ({ ...link, position: i }));
            });
        },
        [],
    );

    const addLink = useCallback(() => {
        if (links.length >= MAX_LINKS) return;
        setLinks((prev) => [...prev, { url: '', label: '', position: prev.length, _clientId: crypto.randomUUID() }]);
    }, [links.length]);

    const removeLink = useCallback((index: number) => {
        setLinks((prev) => prev.filter((_, i) => i !== index).map((link, i) => ({ ...link, position: i })));
    }, []);

    const updateLink = useCallback((index: number, field: 'url' | 'label', value: string) => {
        setLinks((prev) => prev.map((link, i) => (i === index ? { ...link, [field]: value } : link)));
    }, []);

    const displayTitle = resource.title ?? `Resource #${resource.id}`;

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent
                data-testid="setup-lp-modal-content"
                className="flex max-h-[90vh] max-w-2xl flex-col gap-0 overflow-hidden p-0"
            >
                <DialogHeader className="shrink-0 border-b px-6 pt-6 pb-4">
                    <DialogTitle className="flex items-center gap-2">
                        <Globe className="size-5" />
                        Setup Landing Page
                    </DialogTitle>
                    <DialogDescription>
                        Configure the public landing page for:
                        {/* delayDuration aligned with the global TooltipProvider in
                            app-sidebar-layout.tsx to keep tooltip timing consistent
                            across the UI. The local provider is kept so the modal is
                            self-contained when rendered in tests or outside the app shell. */}
                        <TooltipProvider delayDuration={0}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span
                                        data-testid="setup-lp-modal-resource-title"
                                        tabIndex={0}
                                        className="mt-1 block line-clamp-2 wrap-break-word rounded-sm font-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                    >
                                        {displayTitle}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent
                                    data-testid="setup-lp-modal-resource-title-tooltip"
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
                        data-testid="setup-lp-modal-scroll-area"
                        className="flex-1 min-h-0 overflow-y-auto px-6 py-8 text-center text-muted-foreground"
                    >
                        Loading configuration...
                    </div>
                ) : (
                    <div data-testid="setup-lp-modal-scroll-area" className="flex-1 min-h-0 overflow-y-auto px-6 py-4">
                        <div className="space-y-6">
                        {/* Template Selection */}
                        <div className="space-y-2">
                            <Label htmlFor="template">Landing Page Template</Label>
                            <Select
                                value={landingPageTemplateId ? `custom:${landingPageTemplateId}` : template}
                                onValueChange={(val) => {
                                    if (val.startsWith('custom:')) {
                                        const id = Number(val.replace('custom:', ''));
                                        setLandingPageTemplateId(id);
                                        setTemplate('default_gfz'); // Custom templates use default_gfz renderer
                                    } else {
                                        setLandingPageTemplateId(null);
                                        setTemplate(val);
                                    }
                                }}
                            >
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
                                    {customTemplates.filter((ct) => !ct.is_default).length > 0 && (
                                        <>
                                            <SelectSeparator />
                                            <SelectGroup>
                                                <SelectLabel>Custom Templates</SelectLabel>
                                                {customTemplates
                                                    .filter((ct) => !ct.is_default)
                                                    .map((ct) => (
                                                        <SelectItem key={`custom:${ct.id}`} value={`custom:${ct.id}`}>
                                                            <div className="flex flex-col">
                                                                <span>{ct.name}</span>
                                                                <span className="text-xs text-muted-foreground">Custom section order{ct.logo_url ? ' & logo' : ''}</span>
                                                            </div>
                                                        </SelectItem>
                                                    ))}
                                            </SelectGroup>
                                        </>
                                    )}
                                </SelectContent>
                            </Select>
                            <p className="text-sm text-muted-foreground">Choose the design template for your landing page</p>
                        </div>

                        {/* External Landing Page Fields */}
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
                                        onChange={(e) => setExternalPath(e.target.value)}
                                    />
                                    <p className="text-sm text-muted-foreground">Path appended to the domain (e.g. /dataset/12345)</p>
                                </div>

                                {/* External URL Preview */}
                                {externalDomainId && (
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">Resulting URL</Label>
                                        <p className="break-all rounded bg-white/80 px-2 py-1 font-mono text-xs text-blue-800 dark:bg-gray-900/50 dark:text-blue-200">
                                            {(availableDomains.find((d) => d.id === Number(externalDomainId))?.domain ?? '') +
                                                (externalPath || '').replace(/^\/+/, '')}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* FTP URL (hidden for external landing pages, disabled when imported files exist) */}
                        {!isExternal && (
                            <div className="space-y-2">
                                <Label htmlFor="ftp-url">Download URL (FTP)</Label>
                                <Input
                                    id="ftp-url"
                                    type="url"
                                    placeholder="https://datapub.gfz-potsdam.de/download/..."
                                    value={ftpUrl}
                                    onChange={(e) => setFtpUrl(e.target.value)}
                                    disabled={existingConfig?.files && existingConfig.files.length > 0}
                                />
                                {existingConfig?.files && existingConfig.files.length > 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        This field is not used because imported download files are available below.
                                    </p>
                                ) : (
                                    <p className="text-sm text-muted-foreground">Direct link to download the primary data files</p>
                                )}
                            </div>
                        )}

                        {/* Imported download files (read-only, from legacy database) */}
                        {!isExternal && existingConfig?.files && existingConfig.files.length > 0 && (
                            <div className="space-y-2">
                                <Label>Imported Download Files</Label>
                                <div className="space-y-1 rounded-md border bg-muted/50 p-3">
                                    {existingConfig.files.map((file) => (
                                        <a
                                            key={file.id ?? file.url}
                                            href={file.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="block truncate text-sm text-blue-600 hover:underline dark:text-blue-400"
                                        >
                                            {file.url}
                                        </a>
                                    ))}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    These files were imported from the legacy database and cannot be edited here.
                                </p>
                            </div>
                        )}

                        {/* Additional Links (only for GFZ templates, not external or IGSN) */}
                        {supportsLinks && (
                            <div className="space-y-3">
                                <div className="space-y-1">
                                    <Label>Additional Links</Label>
                                    <p className="text-sm text-muted-foreground">
                                        Additional download links displayed below the main download link on the landing page (e.g., GitLab
                                        repository, project website)
                                    </p>
                                </div>

                                {links.length > 0 && (
                                    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                                        <SortableContext
                                            items={links.map((l) => l.id ?? l._clientId ?? `new-${l.position}`)}
                                            strategy={verticalListSortingStrategy}
                                        >
                                            <div className="space-y-2">
                                                {links.map((link, index) => (
                                                    <SortableLinkItem
                                                        key={link.id ?? link._clientId ?? `new-${link.position}`}
                                                        link={link}
                                                        index={index}
                                                        onRemove={removeLink}
                                                        onUpdate={updateLink}
                                                    />
                                                ))}
                                            </div>
                                        </SortableContext>
                                    </DndContext>
                                )}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addLink}
                                    disabled={links.length >= MAX_LINKS}
                                    className="w-full"
                                >
                                    <Plus className="mr-2 size-4" />
                                    Add Link ({links.length}/{MAX_LINKS})
                                </Button>
                            </div>
                        )}

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
                    data-testid="setup-lp-modal-footer"
                    className="shrink-0 flex-wrap gap-2 border-t px-6 py-4"
                >
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
