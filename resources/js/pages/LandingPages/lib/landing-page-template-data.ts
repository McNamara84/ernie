import type { LandingPageConfig, LandingPageResource } from '@/types/landing-page';

import { buildCitation } from './buildCitation';

export function getLandingPageStatus(isPreview: boolean, landingPage: LandingPageConfig | null): string {
    return isPreview ? 'preview' : landingPage?.status || 'published';
}

export function getLandingPageMainTitle(resource: LandingPageResource): string {
    return resource.titles?.find((title) => !title.title_type || title.title_type === 'MainTitle')?.title || 'Untitled';
}

export function getLandingPageSubtitle(resource: LandingPageResource): string | undefined {
    return resource.titles?.find((title) => title.title_type === 'Subtitle')?.title;
}

export function getLandingPageTemplateData(
    resource: LandingPageResource,
    landingPage: LandingPageConfig | null,
    isPreview: boolean,
) {
    return {
        status: getLandingPageStatus(isPreview, landingPage),
        mainTitle: getLandingPageMainTitle(resource),
        subtitle: getLandingPageSubtitle(resource),
        citation: buildCitation(resource),
    };
}