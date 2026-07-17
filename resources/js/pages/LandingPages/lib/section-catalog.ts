import type { LandingPageTemplateConfig, LeftColumnSection, RightColumnSection } from '@/types/landing-page';

import { DESCRIPTION_SECTION_KEYS, LEGACY_DESCRIPTIONS_SECTION_KEY } from './metadata-sections';

export const RIGHT_SECTION_LABELS: Record<RightColumnSection, string> = {
    descriptions: 'Abstract & Descriptions',
    abstract: 'Abstract',
    methods: 'Methods',
    technical_info: 'Technical Information',
    series_information: 'Series Information',
    table_of_contents: 'Table of Contents',
    other: 'Other',
    creators: 'Creators / Authors',
    contributors: 'Contributors',
    funders: 'Funding References',
    keywords: 'Keywords / Subjects',
    metadata_download: 'Metadata Download',
    location: 'Location / Map',
};

export const LEFT_SECTION_LABELS: Record<LeftColumnSection, string> = {
    files: 'Files & Downloads',
    general: 'General',
    acquisition: 'Acquisition',
    citation: 'Cite this Resource',
    dates: 'Dates',
    contact: 'Contact Person',
    model_description: 'Model / Method Description',
    related_work: 'Related Work',
};

export const RIGHT_COLUMN_SECTIONS: RightColumnSection[] = [
    ...DESCRIPTION_SECTION_KEYS,
    'creators',
    'contributors',
    'funders',
    'keywords',
    'metadata_download',
    'location',
];

export const RESOURCE_LEFT_COLUMN_SECTIONS: LeftColumnSection[] = ['files', 'citation', 'dates', 'contact', 'model_description', 'related_work'];

export const IGSN_LEFT_COLUMN_SECTIONS: LeftColumnSection[] = [
    'general',
    'acquisition',
    'citation',
    'dates',
    'contact',
    'model_description',
    'related_work',
];

export const LEFT_COLUMN_SECTIONS: LeftColumnSection[] = [
    'files',
    'general',
    'acquisition',
    'citation',
    'dates',
    'contact',
    'model_description',
    'related_work',
];

export function getCanonicalLeftOrder(templateType: LandingPageTemplateConfig['template_type']): LeftColumnSection[] {
    return templateType === 'igsn' ? IGSN_LEFT_COLUMN_SECTIONS : RESOURCE_LEFT_COLUMN_SECTIONS;
}

function normalizeOrder<T extends string>(stored: readonly T[], canonical: readonly T[]): T[] {
    const canonicalSet = new Set<T>(canonical);
    const seen = new Set<T>();
    const result: T[] = [];

    for (const key of stored) {
        if (!canonicalSet.has(key) || seen.has(key)) continue;
        seen.add(key);
        result.push(key);
    }

    for (const key of canonical) {
        if (!seen.has(key)) {
            result.push(key);
        }
    }

    return result;
}

export function normalizeRightColumnOrder(stored: readonly RightColumnSection[]): RightColumnSection[] {
    const locationBeforeMetadata =
        stored.find((key) => {
            if (key === 'location') return true;
            if (key === LEGACY_DESCRIPTIONS_SECTION_KEY) return true;

            return RIGHT_COLUMN_SECTIONS.includes(key);
        }) === 'location';

    const metadataItems: RightColumnSection[] = [];
    const seen = new Set<RightColumnSection>();

    for (const key of stored) {
        if (key === 'location') {
            continue;
        }

        if (key === LEGACY_DESCRIPTIONS_SECTION_KEY) {
            for (const descriptionKey of DESCRIPTION_SECTION_KEYS) {
                if (seen.has(descriptionKey)) continue;
                seen.add(descriptionKey);
                metadataItems.push(descriptionKey);
            }
            continue;
        }

        if (!RIGHT_COLUMN_SECTIONS.includes(key) || seen.has(key)) {
            continue;
        }

        seen.add(key);
        metadataItems.push(key);
    }

    for (const key of RIGHT_COLUMN_SECTIONS) {
        if (key === 'location' || seen.has(key)) {
            continue;
        }

        seen.add(key);
        metadataItems.push(key);
    }

    return locationBeforeMetadata ? ['location', ...metadataItems] : [...metadataItems, 'location'];
}

export function normalizeLeftColumnOrder(
    stored: readonly LeftColumnSection[],
    templateType: LandingPageTemplateConfig['template_type'],
): LeftColumnSection[] {
    const canonical = getCanonicalLeftOrder(templateType);

    if (stored.includes('citation')) {
        return normalizeOrder<LeftColumnSection>(stored, canonical);
    }

    return [
        ...normalizeOrder<LeftColumnSection>(
            stored,
            canonical.filter((key) => key !== 'citation'),
        ),
        'citation',
    ];
}
