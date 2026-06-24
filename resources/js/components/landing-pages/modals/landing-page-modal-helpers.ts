import { isAxiosError } from 'axios';

import {
    getDefaultIgsnTemplate,
    getDefaultTemplate,
    getIgsnTemplateOptions,
    getTemplateOptions,
    isIgsnLandingPageResourceType,
    type LandingPageConfig,
    type LandingPageDomain,
    type LandingPageLink,
} from '@/types/landing-page';

type BuiltInTemplateType = 'resource' | 'igsn';

const IGSN_TEMPLATE_KEYS = new Set(getIgsnTemplateOptions().map((option) => option.value));

function getBuiltInTemplateType(template: string): BuiltInTemplateType | null {
    if (template === getDefaultIgsnTemplate()) {
        return 'igsn';
    }

    if (template === getDefaultTemplate()) {
        return 'resource';
    }

    return null;
}

export function getPreferredTemplateForResource(resourceType?: string, template?: string | null): string {
    const defaultTemplate = isIgsnLandingPageResourceType(resourceType) ? getDefaultIgsnTemplate() : getDefaultTemplate();
    const validTemplates = new Set(getTemplateOptions(resourceType).map((option) => option.value));

    if (template && validTemplates.has(template)) {
        return template;
    }

    return defaultTemplate;
}

export function getPreferredIgsnTemplate(template?: string | null): string {
    if (template && IGSN_TEMPLATE_KEYS.has(template)) {
        return template;
    }

    return getDefaultIgsnTemplate();
}

export function templateSupportsCustomTemplateId(template: string): boolean {
    return getBuiltInTemplateType(template) !== null;
}

export function getHydratedLandingPageTemplateId(template: string, config?: LandingPageConfig | null): number | null {
    if (!config || !templateSupportsCustomTemplateId(template)) {
        return null;
    }

    if (config.landing_page_template?.is_default) {
        return null;
    }

    const expectedTemplateType = getBuiltInTemplateType(template);
    const templateType = config.landing_page_template?.template_type;

    if (expectedTemplateType !== null && templateType && templateType !== expectedTemplateType) {
        return null;
    }

    return config.landing_page_template_id ?? null;
}

export function getPayloadLandingPageTemplateId(template: string, landingPageTemplateId?: number | null): number | null {
    return templateSupportsCustomTemplateId(template) ? (landingPageTemplateId ?? null) : null;
}

export function normalizeExternalPath(externalPath?: string | null): string | null {
    const normalizedExternalPath = externalPath?.trim() ?? '';

    return normalizedExternalPath === '' ? null : normalizedExternalPath;
}

export function getPreviewableExternalUrl({
    availableDomains,
    externalDomainId,
    externalPath,
    isExternal,
}: {
    availableDomains: LandingPageDomain[];
    externalDomainId: string;
    externalPath: string;
    isExternal: boolean;
}): string | null {
    if (!isExternal || !externalDomainId) {
        return null;
    }

    const normalizedExternalPath = normalizeExternalPath(externalPath);

    if (!normalizedExternalPath) {
        return null;
    }

    const domain = availableDomains.find((availableDomain) => availableDomain.id === Number(externalDomainId));

    if (!domain) {
        return null;
    }

    return domain.domain + normalizedExternalPath.replace(/^\/+/, '');
}

interface BuildLandingPagePayloadOptions {
    template: string;
    landingPageTemplateId?: number | null;
    isPublished?: boolean;
    supportsFtpUrl: boolean;
    ftpUrl?: string;
    supportsDownloadsUnavailable?: boolean;
    downloadsUnavailable?: boolean;
    supportsLinks: boolean;
    links?: LandingPageLink[];
    isExternal: boolean;
    externalDomainId?: string;
    externalPath?: string;
    includeStatus: boolean;
    includeEmptyLinks: boolean;
}

function getCompleteLandingPageLinks(links: LandingPageLink[] = []) {
    return links
        .filter((link) => link.url.trim() !== '' && link.label.trim() !== '')
        .map((link, index) => ({
            url: link.url,
            label: link.label,
            position: index,
        }));
}

function buildLandingPagePayload(options: BuildLandingPagePayloadOptions): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        template: options.template,
        landing_page_template_id: getPayloadLandingPageTemplateId(options.template, options.landingPageTemplateId),
    };

    if (options.includeStatus) {
        payload.status = options.isPublished ? 'published' : 'draft';
    }

    if (options.supportsFtpUrl) {
        payload.ftp_url = options.ftpUrl || null;
    }

    if (options.supportsDownloadsUnavailable) {
        payload.downloads_unavailable = options.downloadsUnavailable === true;
    }

    if (options.isExternal) {
        payload.external_domain_id = options.externalDomainId ? Number(options.externalDomainId) : null;
        payload.external_path = normalizeExternalPath(options.externalPath);
    }

    if (options.supportsLinks) {
        const completeLinks = getCompleteLandingPageLinks(options.links);

        if (options.includeEmptyLinks || completeLinks.length > 0) {
            payload.links = completeLinks;
        }
    }

    return payload;
}

export function buildLandingPageSetupPayload(options: Omit<BuildLandingPagePayloadOptions, 'includeStatus' | 'includeEmptyLinks'>): Record<string, unknown> {
    return buildLandingPagePayload({
        ...options,
        includeStatus: true,
        includeEmptyLinks: true,
    });
}

export function buildLandingPagePreviewPayload(options: Omit<BuildLandingPagePayloadOptions, 'includeStatus' | 'includeEmptyLinks' | 'isPublished'>): Record<string, unknown> {
    return buildLandingPagePayload({
        ...options,
        includeStatus: false,
        includeEmptyLinks: false,
    });
}

export function isLandingPageNotFoundError(error: unknown): boolean {
    return isAxiosError(error) && error.response?.status === 404;
}

export function getLandingPageRequestErrorMessage(error: unknown, fallback: string): string {
    if (!isAxiosError(error)) {
        return fallback;
    }

    const data = error.response?.data;

    if (typeof data === 'object' && data !== null && 'message' in data && typeof data.message === 'string') {
        return data.message;
    }

    if (typeof data === 'object' && data !== null && 'errors' in data && typeof data.errors === 'object' && data.errors !== null) {
        return Object.values(data.errors as Record<string, string | string[]>).flat().join(', ');
    }

    return fallback;
}