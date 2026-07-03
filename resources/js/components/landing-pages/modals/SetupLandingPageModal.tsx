import type { DragEndEvent } from '@dnd-kit/core';
import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import axios from 'axios';
import { AlertTriangle, Copy, Eye, Globe, GripVertical, Plus, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { ExternalLandingPageFields } from '@/components/landing-pages/modals/ExternalLandingPageFields';
import {
    buildLandingPagePreviewPayload,
    buildLandingPageSetupPayload,
    getHydratedLandingPageTemplateId,
    getLandingPageRequestErrorMessage,
    getPreferredTemplateForResource,
    getPreviewableExternalUrl,
    isLandingPageNotFoundError,
} from '@/components/landing-pages/modals/landing-page-modal-helpers';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    getTemplateOptions,
    isIgsnLandingPageResourceType,
    type LandingPageConfig,
    type LandingPageDomain,
    type LandingPageDownloadUrlSuggestionItem,
    type LandingPageDownloadUrlSuggestions,
    type LandingPageLink,
    type LandingPageTemplateSummary,
} from '@/types/landing-page';

interface Resource {
    id: number;
    doi?: string | null;
    title?: string;
    resourcetypegeneral?: string;
    [key: string]: unknown;
}

interface SetupLandingPageModalProps {
    resource: Resource;
    isOpen: boolean;
    onClose: () => void;
    onSuccess?: (landingPage?: LandingPageConfig | null) => void;
    existingConfig?: LandingPageConfig | null;
}

const EMPTY_DOWNLOAD_URL_SUGGESTIONS: LandingPageDownloadUrlSuggestions = {
    domains: [],
    urls: [],
};

const LANDING_PAGE_DRAFT_STORAGE_PREFIX = 'setup-landing-page-modal:draft';
const LANDING_PAGE_PREVIEW_PLACEHOLDER_URL = 'about:blank';
const LANDING_PAGE_POPUP_BLOCKED_MESSAGE = 'Your browser blocked the landing page tab. Please allow pop-ups for ERNIE and try again.';

type DownloadUrlSuggestionEntry = {
    id: string;
    value: string;
    usageCount: number;
};

function openLandingPagePreviewPlaceholder(): Window | null {
    const previewWindow = window.open(LANDING_PAGE_PREVIEW_PLACEHOLDER_URL, '_blank');

    if (previewWindow) {
        previewWindow.opener = null;
    }

    return previewWindow;
}

type PersistedLandingPageDraftState = {
    template: string;
    ftpUrl: string;
    downloadsUnavailable: boolean;
    isPublished: boolean;
    externalDomainId: string;
    externalPath: string;
    landingPageTemplateId: number | null;
    links: LandingPageLink[];
};

function cloneLandingPageLinks(links: LandingPageLink[] = []): LandingPageLink[] {
    return links.map((link, index) => ({
        id: link.id,
        _clientId: link._clientId,
        url: link.url,
        label: link.label,
        position: typeof link.position === 'number' ? link.position : index,
    }));
}

function normalizePersistedLandingPageDraftState(draftState: PersistedLandingPageDraftState) {
    return {
        template: draftState.template,
        ftpUrl: draftState.ftpUrl,
        downloadsUnavailable: draftState.downloadsUnavailable,
        isPublished: draftState.isPublished,
        externalDomainId: draftState.externalDomainId,
        externalPath: draftState.externalPath,
        landingPageTemplateId: draftState.landingPageTemplateId,
        links: cloneLandingPageLinks(draftState.links).map((link, index) => ({
            url: link.url,
            label: link.label,
            position: typeof link.position === 'number' ? link.position : index,
        })),
    };
}

function arePersistedLandingPageDraftStatesEqual(
    left: PersistedLandingPageDraftState,
    right: PersistedLandingPageDraftState,
): boolean {
    return JSON.stringify(normalizePersistedLandingPageDraftState(left)) === JSON.stringify(normalizePersistedLandingPageDraftState(right));
}

function parsePersistedLandingPageDraftState(rawValue: string | null): PersistedLandingPageDraftState | null {
    if (!rawValue) {
        return null;
    }

    try {
        const parsed = JSON.parse(rawValue) as Partial<PersistedLandingPageDraftState> | null;

        if (!parsed || typeof parsed !== 'object' || typeof parsed.template !== 'string') {
            return null;
        }

        const links = Array.isArray(parsed.links)
            ? parsed.links.map((link, index) => {
                if (!link || typeof link !== 'object') {
                    return {
                        url: '',
                        label: '',
                        position: index,
                    } satisfies LandingPageLink;
                }

                const candidate = link as Partial<LandingPageLink>;

                return {
                    id: typeof candidate.id === 'number' ? candidate.id : undefined,
                    _clientId: typeof candidate._clientId === 'string' ? candidate._clientId : undefined,
                    url: typeof candidate.url === 'string' ? candidate.url : '',
                    label: typeof candidate.label === 'string' ? candidate.label : '',
                    position: typeof candidate.position === 'number' ? candidate.position : index,
                } satisfies LandingPageLink;
            })
            : [];

        return {
            template: parsed.template,
            ftpUrl: typeof parsed.ftpUrl === 'string' ? parsed.ftpUrl : '',
            downloadsUnavailable: parsed.downloadsUnavailable === true,
            isPublished: parsed.isPublished === true,
            externalDomainId: typeof parsed.externalDomainId === 'string' ? parsed.externalDomainId : '',
            externalPath: typeof parsed.externalPath === 'string' ? parsed.externalPath : '',
            landingPageTemplateId: typeof parsed.landingPageTemplateId === 'number' ? parsed.landingPageTemplateId : null,
            links,
        };
    } catch {
        return null;
    }
}

function readSessionStorageItem(key: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeSessionStorageItem(key: string, value: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(key, value);
    } catch {
        // Ignore storage errors so the modal remains usable.
    }
}

function removeSessionStorageItem(key: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // Ignore storage errors so the modal remains usable.
    }
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
    // PhysicalObject resources (IGSNs) default to the IGSN renderer; everything
    // else uses the standard `default_gfz` template.
    const initialTemplate = getPreferredTemplateForResource(resource.resourcetypegeneral, existingConfig?.template);
    const storageKey = resource.id ? `${LANDING_PAGE_DRAFT_STORAGE_PREFIX}:${resource.id}` : null;
    const hydratedDraftStateKeyRef = useRef<string | null>(null);
    const currentDraftScope = isOpen ? storageKey : null;

    useEffect(() => {
        hydratedDraftStateKeyRef.current = null;
    }, [currentDraftScope]);

    const readPersistedDraftState = useCallback((): PersistedLandingPageDraftState | null => {
        if (typeof window === 'undefined' || storageKey === null) {
            return null;
        }

        const persistedDraft = parsePersistedLandingPageDraftState(readSessionStorageItem(storageKey));

        if (!persistedDraft) {
            return null;
        }

        return {
            ...persistedDraft,
            template: getPreferredTemplateForResource(resource.resourcetypegeneral, persistedDraft.template),
            links: cloneLandingPageLinks(persistedDraft.links),
        };
    }, [resource.resourcetypegeneral, storageKey]);

    const persistDraftState = useCallback((draftState: PersistedLandingPageDraftState) => {
        if (typeof window === 'undefined' || storageKey === null) {
            return;
        }

        writeSessionStorageItem(storageKey, JSON.stringify({
            ...draftState,
            links: cloneLandingPageLinks(draftState.links),
        }));
    }, [storageKey]);

    const clearPersistedDraftState = useCallback(() => {
        if (typeof window === 'undefined' || storageKey === null) {
            return;
        }

        removeSessionStorageItem(storageKey);
    }, [storageKey]);

    const buildDraftStateFromConfig = useCallback((config: LandingPageConfig | null): PersistedLandingPageDraftState => {
        const preferredTemplate = getPreferredTemplateForResource(resource.resourcetypegeneral, config?.template);

        return {
            template: preferredTemplate,
            ftpUrl: config?.ftp_url ?? '',
            downloadsUnavailable: config?.downloads_unavailable === true,
            isPublished: (config?.status ?? 'draft') === 'published',
            externalDomainId: String(config?.external_domain_id ?? ''),
            externalPath: config?.external_path ?? '',
            landingPageTemplateId: getHydratedLandingPageTemplateId(preferredTemplate, config),
            links: cloneLandingPageLinks(config?.links ?? []),
        };
    }, [resource.resourcetypegeneral]);

    const applyDraftState = useCallback((draftState: PersistedLandingPageDraftState) => {
        setTemplate(draftState.template);
        setFtpUrl(draftState.ftpUrl);
        setDownloadsUnavailable(draftState.downloadsUnavailable);
        setIsPublished(draftState.isPublished);
        setExternalDomainId(draftState.externalDomainId);
        setExternalPath(draftState.externalPath);
        setLinks(cloneLandingPageLinks(draftState.links));
        setLandingPageTemplateId(draftState.landingPageTemplateId);
    }, []);

    const [template, setTemplate] = useState<string>(initialTemplate);
    const [ftpUrl, setFtpUrl] = useState<string>(existingConfig?.ftp_url ?? '');
    const [downloadsUnavailable, setDownloadsUnavailable] = useState<boolean>(existingConfig?.downloads_unavailable === true);
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

    // Download URL suggestion state
    const [downloadUrlSuggestions, setDownloadUrlSuggestions] = useState<LandingPageDownloadUrlSuggestions>(EMPTY_DOWNLOAD_URL_SUGGESTIONS);
    const [downloadUrlSuggestionsLoaded, setDownloadUrlSuggestionsLoaded] = useState(false);
    const [downloadUrlSuggestionsLoading, setDownloadUrlSuggestionsLoading] = useState(false);
    const [downloadUrlSuggestionsOpen, setDownloadUrlSuggestionsOpen] = useState(false);
    const [downloadUrlSuggestionQuery, setDownloadUrlSuggestionQuery] = useState('');
    const [activeDownloadUrlSuggestionIndex, setActiveDownloadUrlSuggestionIndex] = useState<number | null>(null);
    const [hasHydratedDraftState, setHasHydratedDraftState] = useState(false);

    // Landing page template ID (for custom templates)
    const [landingPageTemplateId, setLandingPageTemplateId] = useState<number | null>(
        getHydratedLandingPageTemplateId(initialTemplate, existingConfig),
    );

    // Additional links state
    const [links, setLinks] = useState<LandingPageLink[]>(existingConfig?.links ?? []);

    const isExternal = template === 'external';
    const isIgsn = template === 'default_gfz_igsn';
    const supportsFtpUrl = !isExternal && !isIgsn;
    const supportsDownloadsUnavailable = supportsFtpUrl;
    const supportsLinks = !isExternal && !isIgsn;
    const MAX_LINKS = 10;

    // Resource type drives which built-in templates are offered and which
    // custom templates are eligible.
    //
    // - Built-in templates: `getTemplateOptions(resource.resourcetypegeneral)`
    //   returns the built-in templates valid for the current setup flow.
    //   PhysicalObject resources are treated as IGSN landing pages, so they
    //   only see IGSN-compatible built-ins plus shared options such as
    //   `external`. Everything else stays on the resource template path.
    // - Custom templates: filtered strictly to the resource's eligible
    //   `template_type` (PhysicalObject → `igsn`, otherwise → `resource`).
    const isPhysicalObject = isIgsnLandingPageResourceType(resource.resourcetypegeneral);
    const eligibleTemplateType: 'resource' | 'igsn' = isPhysicalObject ? 'igsn' : 'resource';
    const eligibleCustomTemplates = useMemo(
        () => customTemplates.filter(
            (ct) => !ct.is_default && (ct.template_type ?? 'resource') === eligibleTemplateType,
        ),
        [customTemplates, eligibleTemplateType],
    );
    const builtInTemplateOptions = useMemo(
        () => getTemplateOptions(resource.resourcetypegeneral),
        [resource.resourcetypegeneral],
    );
    const importedDownloadFiles = currentConfig?.files ?? existingConfig?.files ?? [];
    const hasImportedFiles = importedDownloadFiles.length > 0;
    const currentDraftState = useMemo<PersistedLandingPageDraftState>(() => ({
        template,
        ftpUrl,
        downloadsUnavailable,
        isPublished,
        externalDomainId,
        externalPath,
        landingPageTemplateId,
        links: cloneLandingPageLinks(links),
    }), [downloadsUnavailable, externalDomainId, externalPath, ftpUrl, isPublished, landingPageTemplateId, links, template]);
    const baselineDraftState = useMemo(
        () => buildDraftStateFromConfig(currentConfig),
        [buildDraftStateFromConfig, currentConfig],
    );

    const applyConfigState = useCallback((config: LandingPageConfig | null) => {
        const baseDraftState = buildDraftStateFromConfig(config);
        const persistedDraftState = readPersistedDraftState();

        hydratedDraftStateKeyRef.current = storageKey;
        setCurrentConfig(config);
        setPreviewUrl(config?.preview_url ?? '');
        applyDraftState(persistedDraftState ?? baseDraftState);
        setHasHydratedDraftState(true);
    }, [applyDraftState, buildDraftStateFromConfig, readPersistedDraftState, storageKey]);

    useEffect(() => {
        if (!isOpen || !hasHydratedDraftState || hydratedDraftStateKeyRef.current !== storageKey) {
            return;
        }

        if (arePersistedLandingPageDraftStatesEqual(currentDraftState, baselineDraftState)) {
            clearPersistedDraftState();
            return;
        }

        persistDraftState(currentDraftState);
    }, [
        baselineDraftState,
        clearPersistedDraftState,
        currentDraftState,
        hasHydratedDraftState,
        isOpen,
        persistDraftState,
        storageKey,
    ]);

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

    const loadDownloadUrlSuggestions = async () => {
        if (downloadUrlSuggestionsLoaded || downloadUrlSuggestionsLoading) {
            return;
        }

        setDownloadUrlSuggestionsLoading(true);

        try {
            const response = await axios.get<{ suggestions: LandingPageDownloadUrlSuggestions }>('/api/landing-page-download-url-suggestions');
            setDownloadUrlSuggestions(response.data.suggestions ?? EMPTY_DOWNLOAD_URL_SUGGESTIONS);
            setDownloadUrlSuggestionsLoaded(true);
        } catch (error) {
            console.error('Failed to load landing page download URL suggestions:', error);
            setDownloadUrlSuggestions(EMPTY_DOWNLOAD_URL_SUGGESTIONS);
            setDownloadUrlSuggestionsLoaded(true);
        } finally {
            setDownloadUrlSuggestionsLoading(false);
        }
    };

    const loadLandingPageConfig = useCallback(async () => {
        setIsLoading(true);
        try {
            const response = await axios.get<{ landing_page: LandingPageConfig }>(`/resources/${resource.id}/landing-page`);
            applyConfigState(response.data.landing_page);
        } catch (error) {
            if (isLandingPageNotFoundError(error)) {
                applyConfigState(null);
            } else {
                console.error('Failed to load landing page config:', error);
                toast.error('Failed to load landing page configuration');
                applyConfigState(null);
            }
        } finally {
            setIsLoading(false);
        }
    }, [applyConfigState, resource.id]);

    // Load existing config when modal opens
    useEffect(() => {
        if (isOpen && resource.id) {
            setHasHydratedDraftState(false);
            if (existingConfig) {
                applyConfigState(existingConfig);
            } else {
                void loadLandingPageConfig();
            }
            void loadAvailableDomains();
            void loadCustomTemplates();
        } else if (!isOpen) {
            setHasHydratedDraftState(false);
            setCurrentConfig(null);
            setPreviewUrl('');
            applyDraftState(buildDraftStateFromConfig(null));
            setDownloadUrlSuggestions(EMPTY_DOWNLOAD_URL_SUGGESTIONS);
            setDownloadUrlSuggestionsLoaded(false);
            setDownloadUrlSuggestionsLoading(false);
            setDownloadUrlSuggestionsOpen(false);
            setDownloadUrlSuggestionQuery('');
            setActiveDownloadUrlSuggestionIndex(null);
        }
    }, [applyConfigState, applyDraftState, buildDraftStateFromConfig, existingConfig, isOpen, loadLandingPageConfig, resource.id]);

    useEffect(() => {
        if (!supportsFtpUrl || hasImportedFiles) {
            setDownloadUrlSuggestionsOpen(false);
            setDownloadUrlSuggestionQuery('');
            setActiveDownloadUrlSuggestionIndex(null);
        }
    }, [hasImportedFiles, supportsFtpUrl]);

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
                supportsFtpUrl,
                ftpUrl,
                supportsDownloadsUnavailable,
                downloadsUnavailable,
                supportsLinks,
                links,
                isExternal,
                externalDomainId,
                externalPath,
            });

            const url = `/resources/${resource.id}/landing-page`;
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

            if (response.data.landing_page) {
                clearPersistedDraftState();
                applyConfigState(response.data.landing_page);
            }

            try {
                await axios.delete(`/resources/${resource.id}/landing-page/preview`);
            } catch {
                // Ignore errors from clearing preview session
            }

            onSuccess?.(response.data.landing_page ?? null);
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
            clearPersistedDraftState();
            setCurrentConfig(null);
            setPreviewUrl('');
            applyDraftState(buildDraftStateFromConfig(null));
            toast.success('Landing page preview removed successfully');
            onSuccess?.(null);
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
        const currentTemplate = getPreferredTemplateForResource(resource.resourcetypegeneral, currentConfig.template);
        const currentLandingPageTemplateId = getHydratedLandingPageTemplateId(currentTemplate, currentConfig);
        const baseChanges =
            template !== currentTemplate ||
            // ftpUrl is irrelevant for external and IGSN templates.
            (supportsFtpUrl && ftpUrl !== (currentConfig.ftp_url ?? '')) ||
            (supportsDownloadsUnavailable && downloadsUnavailable !== (currentConfig.downloads_unavailable === true)) ||
            isPublished !== (currentConfig.status === 'published') ||
            landingPageTemplateId !== currentLandingPageTemplateId;

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
    }, [currentConfig, template, ftpUrl, downloadsUnavailable, isPublished, externalDomainId, externalPath, links, landingPageTemplateId, resource.resourcetypegeneral, supportsFtpUrl, supportsDownloadsUnavailable]);

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
        return getPreviewableExternalUrl({
            availableDomains,
            externalDomainId,
            externalPath,
            isExternal,
        });
    }, [isExternal, externalDomainId, externalPath, availableDomains]);

    const filteredDownloadUrlSuggestions = useMemo(() => {
        const normalizedQuery = downloadUrlSuggestionQuery.trim().toLowerCase();

        const filterSuggestions = (suggestions: LandingPageDownloadUrlSuggestionItem[]) => {
            if (normalizedQuery === '') {
                return suggestions;
            }

            return suggestions.filter((suggestion) => suggestion.value.toLowerCase().includes(normalizedQuery));
        };

        return {
            domains: filterSuggestions(downloadUrlSuggestions.domains),
            urls: filterSuggestions(downloadUrlSuggestions.urls),
        };
    }, [downloadUrlSuggestionQuery, downloadUrlSuggestions]);

    const visibleDownloadUrlSuggestionEntries = useMemo(
        () => [
            ...filteredDownloadUrlSuggestions.domains.map((suggestion, index): DownloadUrlSuggestionEntry => ({
                id: `ftp-url-domain-suggestion-${index}`,
                value: suggestion.value,
                usageCount: suggestion.usage_count,
            })),
            ...filteredDownloadUrlSuggestions.urls.map((suggestion, index): DownloadUrlSuggestionEntry => ({
                id: `ftp-url-full-suggestion-${index}`,
                value: suggestion.value,
                usageCount: suggestion.usage_count,
            })),
        ],
        [filteredDownloadUrlSuggestions.domains, filteredDownloadUrlSuggestions.urls],
    );

    const shouldShowDownloadUrlSuggestions = supportsFtpUrl && !hasImportedFiles && downloadUrlSuggestionsOpen;
    const hasVisibleDownloadUrlSuggestions = filteredDownloadUrlSuggestions.domains.length > 0 || filteredDownloadUrlSuggestions.urls.length > 0;
    const activeDownloadUrlSuggestion = activeDownloadUrlSuggestionIndex === null
        ? null
        : (visibleDownloadUrlSuggestionEntries[activeDownloadUrlSuggestionIndex] ?? null);

    useEffect(() => {
        if (!shouldShowDownloadUrlSuggestions) {
            setActiveDownloadUrlSuggestionIndex(null);

            return;
        }

        if (
            activeDownloadUrlSuggestionIndex !== null
            && activeDownloadUrlSuggestionIndex >= visibleDownloadUrlSuggestionEntries.length
        ) {
            setActiveDownloadUrlSuggestionIndex(null);
        }
    }, [activeDownloadUrlSuggestionIndex, shouldShowDownloadUrlSuggestions, visibleDownloadUrlSuggestionEntries.length]);

    const openDownloadUrlSuggestions = () => {
        if (!supportsFtpUrl || hasImportedFiles) {
            return;
        }

        setDownloadUrlSuggestionsOpen(true);
        setActiveDownloadUrlSuggestionIndex(null);

        void loadDownloadUrlSuggestions();
    };

    const applyDownloadUrlSuggestion = (value: string) => {
        setFtpUrl(value);
        setDownloadUrlSuggestionQuery('');
        setDownloadUrlSuggestionsOpen(false);
        setActiveDownloadUrlSuggestionIndex(null);
    };

    const moveActiveDownloadUrlSuggestion = (direction: 'next' | 'previous') => {
        if (visibleDownloadUrlSuggestionEntries.length === 0) {
            return;
        }

        setActiveDownloadUrlSuggestionIndex((currentIndex) => {
            if (currentIndex === null) {
                return direction === 'next' ? 0 : visibleDownloadUrlSuggestionEntries.length - 1;
            }

            if (direction === 'next') {
                return (currentIndex + 1) % visibleDownloadUrlSuggestionEntries.length;
            }

            return (currentIndex - 1 + visibleDownloadUrlSuggestionEntries.length) % visibleDownloadUrlSuggestionEntries.length;
        });
    };

    const openPreview = async () => {
        // For external landing pages, open the external URL directly
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
        // This allows users to preview template changes before saving
        if (hasUnsavedChanges || !currentConfig) {
            if (!resource.id) {
                toast.error('Unable to generate preview');
                return;
            }

            const previewWindow = openLandingPagePreviewPlaceholder();

            if (!previewWindow) {
                toast.error(LANDING_PAGE_POPUP_BLOCKED_MESSAGE);
                return;
            }

            try {
                const payload = buildLandingPagePreviewPayload({
                    template,
                    landingPageTemplateId,
                    supportsFtpUrl,
                    ftpUrl,
                    supportsDownloadsUnavailable,
                    downloadsUnavailable,
                    supportsLinks,
                    links,
                    isExternal,
                    externalDomainId,
                    externalPath,
                });

                // Store preview in session and get preview URL
                const response = await axios.post<{ preview_url: string }>(`/resources/${resource.id}/landing-page/preview`, payload);

                // Open preview in the tab that was created synchronously by the click handler.
                const previewUrlFromServer = response.data?.preview_url;
                const fallbackPreviewUrl = `/resources/${resource.id}/landing-page/preview`;
                previewWindow.location.href = previewUrlFromServer || fallbackPreviewUrl;
            } catch (error) {
                previewWindow.close();
                console.error('Failed to create preview:', error);
                toast.error(getLandingPageRequestErrorMessage(error, 'Failed to create preview'));
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
                                        // Resolve the renderer key from the selected custom
                                        // template's template_type (resource → default_gfz,
                                        // igsn → default_gfz_igsn) so the correct landing
                                        // page renderer is used regardless of which custom
                                        // template the curator picks.
                                        const selected = customTemplates.find((ct) => ct.id === id);
                                        const rendererKey = (selected?.template_type ?? 'resource') === 'igsn'
                                            ? 'default_gfz_igsn'
                                            : 'default_gfz';
                                        setTemplate(rendererKey);
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
                                    {builtInTemplateOptions.map((tmpl) => (
                                        <SelectItem key={tmpl.value} value={tmpl.value}>
                                            <div className="flex flex-col">
                                                <span>{tmpl.label}</span>
                                                <span className="text-xs text-muted-foreground">{tmpl.description}</span>
                                            </div>
                                        </SelectItem>
                                    ))}
                                    {eligibleCustomTemplates.length > 0 && (
                                        <>
                                            <SelectSeparator />
                                            <SelectGroup>
                                                <SelectLabel>Custom Templates</SelectLabel>
                                                {eligibleCustomTemplates.map((ct) => (
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
                            <ExternalLandingPageFields
                                availableDomains={availableDomains}
                                externalDomainId={externalDomainId}
                                onExternalDomainIdChange={setExternalDomainId}
                                externalPath={externalPath}
                                onExternalPathChange={setExternalPath}
                                computedExternalUrl={computedExternalUrl}
                                pathExample="/dataset/12345"
                            />
                        )}

                        {/* FTP URL (hidden for external landing pages, disabled when imported files exist) */}
                        {supportsFtpUrl && (
                            <div className="space-y-2">
                                <Label htmlFor="ftp-url">Download URL</Label>
                                <div
                                    className="relative"
                                    onFocusCapture={openDownloadUrlSuggestions}
                                    onBlurCapture={(event) => {
                                        if (event.relatedTarget instanceof Node && event.currentTarget.contains(event.relatedTarget)) {
                                            return;
                                        }

                                        setDownloadUrlSuggestionsOpen(false);
                                        setDownloadUrlSuggestionQuery('');
                                        setActiveDownloadUrlSuggestionIndex(null);
                                    }}
                                >
                                    <Input
                                        id="ftp-url"
                                        type="url"
                                        role="combobox"
                                        placeholder="https://datapub.gfz-potsdam.de/download/..."
                                        value={ftpUrl}
                                        onChange={(e) => {
                                            setFtpUrl(e.target.value);
                                            setDownloadUrlSuggestionQuery(e.target.value);
                                            setActiveDownloadUrlSuggestionIndex(null);

                                            if (!downloadUrlSuggestionsOpen) {
                                                setDownloadUrlSuggestionsOpen(true);
                                            }

                                            void loadDownloadUrlSuggestions();
                                        }}
                                        onKeyDown={(event) => {
                                            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !downloadUrlSuggestionsOpen) {
                                                setDownloadUrlSuggestionsOpen(true);
                                                setActiveDownloadUrlSuggestionIndex(null);
                                                void loadDownloadUrlSuggestions();
                                            }

                                            if (event.key === 'ArrowDown') {
                                                event.preventDefault();
                                                moveActiveDownloadUrlSuggestion('next');

                                                return;
                                            }

                                            if (event.key === 'ArrowUp') {
                                                event.preventDefault();
                                                moveActiveDownloadUrlSuggestion('previous');

                                                return;
                                            }

                                            if (event.key === 'Enter' && activeDownloadUrlSuggestion !== null) {
                                                event.preventDefault();
                                                applyDownloadUrlSuggestion(activeDownloadUrlSuggestion.value);

                                                return;
                                            }

                                            if (event.key === 'Escape') {
                                                setDownloadUrlSuggestionsOpen(false);
                                                setDownloadUrlSuggestionQuery('');
                                                setActiveDownloadUrlSuggestionIndex(null);
                                            }
                                        }}
                                        disabled={hasImportedFiles}
                                        autoComplete="off"
                                        aria-activedescendant={activeDownloadUrlSuggestion?.id}
                                        aria-autocomplete="list"
                                        aria-expanded={shouldShowDownloadUrlSuggestions}
                                        aria-controls={shouldShowDownloadUrlSuggestions ? 'ftp-url-suggestions' : undefined}
                                        aria-haspopup="listbox"
                                    />

                                    {shouldShowDownloadUrlSuggestions && (
                                        <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md">
                                            <div id="ftp-url-suggestions" role="listbox" className="max-h-64 overflow-y-auto py-1">
                                                {downloadUrlSuggestionsLoading ? (
                                                    <div className="px-3 py-2 text-sm text-muted-foreground" role="status">
                                                        Loading suggestions...
                                                    </div>
                                                ) : !hasVisibleDownloadUrlSuggestions ? (
                                                    <div className="px-3 py-2 text-sm text-muted-foreground">No matching suggestions.</div>
                                                ) : (
                                                    <>
                                                        {filteredDownloadUrlSuggestions.domains.length > 0 && (
                                                            <div className="p-1 text-foreground">
                                                                <div className="px-2 py-1.5 text-xs font-medium text-muted-foreground">Suggested domains</div>
                                                                {filteredDownloadUrlSuggestions.domains.map((suggestion, index) => {
                                                                    const suggestionId = `ftp-url-domain-suggestion-${index}`;
                                                                    const suggestionIndex = index;
                                                                    const isActive = activeDownloadUrlSuggestion?.id === suggestionId;

                                                                    return (
                                                                        <div
                                                                            key={`domain-${suggestion.value}`}
                                                                            id={suggestionId}
                                                                            role="option"
                                                                            aria-selected={isActive}
                                                                            className={cn(
                                                                                'relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none',
                                                                                isActive ? 'bg-accent text-accent-foreground' : 'text-foreground',
                                                                            )}
                                                                            onMouseDown={(event) => event.preventDefault()}
                                                                            onMouseEnter={() => setActiveDownloadUrlSuggestionIndex(suggestionIndex)}
                                                                            onClick={() => applyDownloadUrlSuggestion(suggestion.value)}
                                                                        >
                                                                            <span className="truncate">{suggestion.value}</span>
                                                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                                                {suggestion.usage_count}x
                                                                            </span>
                                                                        </div>
                                                                    );
                                                                })}
                                                            </div>
                                                        )}

                                                        {filteredDownloadUrlSuggestions.urls.length > 0 && (
                                                            <div className="p-1 text-foreground">
                                                                <div className="px-2 py-1.5 text-xs font-medium text-muted-foreground">Previously used full URLs</div>
                                                                {filteredDownloadUrlSuggestions.urls.map((suggestion, index) => {
                                                                    const suggestionId = `ftp-url-full-suggestion-${index}`;
                                                                    const suggestionIndex = filteredDownloadUrlSuggestions.domains.length + index;
                                                                    const isActive = activeDownloadUrlSuggestion?.id === suggestionId;

                                                                    return (
                                                                        <div
                                                                            key={`url-${suggestion.value}`}
                                                                            id={suggestionId}
                                                                            role="option"
                                                                            aria-selected={isActive}
                                                                            className={cn(
                                                                                'relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none',
                                                                                isActive ? 'bg-accent text-accent-foreground' : 'text-foreground',
                                                                            )}
                                                                            onMouseDown={(event) => event.preventDefault()}
                                                                            onMouseEnter={() => setActiveDownloadUrlSuggestionIndex(suggestionIndex)}
                                                                            onClick={() => applyDownloadUrlSuggestion(suggestion.value)}
                                                                        >
                                                                            <span className="truncate">{suggestion.value}</span>
                                                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                                                {suggestion.usage_count}x
                                                                            </span>
                                                                        </div>
                                                                    );
                                                                })}
                                                            </div>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                                {hasImportedFiles ? (
                                    <p className="text-sm text-muted-foreground">
                                        This field is not used because imported download files are available below.
                                    </p>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Direct link to download the primary data files. Start typing or pick a suggested domain or full URL from
                                        existing landing pages.
                                    </p>
                                )}

                                {supportsDownloadsUnavailable && (
                                    <div className="space-y-2 rounded-md border p-3">
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="downloads-unavailable"
                                                checked={downloadsUnavailable}
                                                onCheckedChange={(checked) => setDownloadsUnavailable(checked === true)}
                                                className="mt-0.5"
                                            />
                                            <div className="space-y-1">
                                                <Label htmlFor="downloads-unavailable" className="text-sm font-medium">
                                                    No data available for download
                                                </Label>
                                                <p className="text-sm text-muted-foreground">
                                                    Hide the Files section on the landing page while keeping saved download values for later use.
                                                </p>
                                            </div>
                                        </div>

                                        {downloadsUnavailable && hasImportedFiles && (
                                            <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                                                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                                <p>Imported download files will be hidden on the public landing page while this option is enabled.</p>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Imported download files (read-only, from legacy database) */}
                        {!isExternal && hasImportedFiles && (
                            <div className="space-y-2">
                                <Label>Imported Download Files</Label>
                                <div className="space-y-1 rounded-md border bg-muted/50 p-3">
                                    {importedDownloadFiles.map((file) => (
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
