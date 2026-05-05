import {
    getDefaultIgsnTemplate,
    getDefaultTemplate,
    getIgsnTemplateOptions,
    getTemplateOptions,
    isIgsnLandingPageResourceType,
    type LandingPageConfig,
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