import type { IgsnSection, LandingPageTemplateConfig, LeftColumnSection, RightColumnSection, TemplateSection } from '@/types/landing-page';

import { DESCRIPTION_SECTION_KEYS, LEGACY_DESCRIPTIONS_SECTION_KEY } from './metadata-sections';

export const RIGHT_SECTION_LABELS: Record<RightColumnSection, string> = {
    descriptions: 'Abstract & Descriptions',
    abstract: 'Abstract',
    methods: 'Methods',
    technical_info: 'Technical Information',
    series_information: 'Series Information',
    table_of_contents: 'Table of Contents',
    other: 'Additional Information',
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
    sample_family: 'Sample Family',
    acquisition: 'Acquisition',
    repositories: 'Repositories',
    citation: 'Cite this Resource',
    dates: 'Dates',
    contact: 'Contact Person',
    model_description: 'Model / Method Description',
    related_work: 'Related Work',
};

export const IGSN_SECTION_LABELS: Record<IgsnSection, string> = {
    general: 'General',
    sample_family: 'Sample Family',
    acquisition: 'Acquisition',
    repositories: 'Repositories',
    citation: 'Cite this Resource',
    dates: 'Dates',
    contact: 'Contact Person',
    model_description: 'Model / Method Description',
    related_work: 'Related Work',
    abstract: 'Abstract',
    methods: 'Methods',
    technical_info: 'Technical Information',
    series_information: 'Series Information',
    table_of_contents: 'Table of Contents',
    other: 'Additional Information',
    creators: 'Creators / Authors',
    contributors: 'Contributors',
    funders: 'Funding References',
    keywords: 'Keywords / Subjects',
    metadata_download: 'Metadata Download',
    sample_image: 'Sample Image',
    location: 'Location / Map',
};

export const SECTION_LABELS: Record<TemplateSection, string> = {
    ...RIGHT_SECTION_LABELS,
    ...LEFT_SECTION_LABELS,
    ...IGSN_SECTION_LABELS,
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
    'sample_family',
    'acquisition',
    'repositories',
    'citation',
    'dates',
    'contact',
    'model_description',
    'related_work',
];

export const IGSN_RIGHT_COLUMN_SECTIONS: IgsnSection[] = [
    ...DESCRIPTION_SECTION_KEYS,
    'creators',
    'contributors',
    'funders',
    'keywords',
    'metadata_download',
    'sample_image',
    'location',
];

export const IGSN_SECTIONS: IgsnSection[] = [...(IGSN_LEFT_COLUMN_SECTIONS as IgsnSection[]), ...IGSN_RIGHT_COLUMN_SECTIONS];

export const LEFT_COLUMN_SECTIONS: LeftColumnSection[] = [
    'files',
    'general',
    'sample_family',
    'acquisition',
    'repositories',
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

export function normalizeRightColumnOrder(stored: readonly TemplateSection[]): RightColumnSection[] {
    const locationBeforeMetadata =
        stored.find((key) => {
            if (key === 'location') return true;
            if (key === LEGACY_DESCRIPTIONS_SECTION_KEY) return true;

            return RIGHT_COLUMN_SECTIONS.includes(key as RightColumnSection);
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

        if (!RIGHT_COLUMN_SECTIONS.includes(key as RightColumnSection) || seen.has(key as RightColumnSection)) {
            continue;
        }

        seen.add(key as RightColumnSection);
        metadataItems.push(key as RightColumnSection);
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
    stored: readonly TemplateSection[],
    templateType: LandingPageTemplateConfig['template_type'],
): LeftColumnSection[] {
    const canonical = getCanonicalLeftOrder(templateType);

    if (stored.includes('citation')) {
        return normalizeOrder<LeftColumnSection>(stored as readonly LeftColumnSection[], canonical);
    }

    return [
        ...normalizeOrder<LeftColumnSection>(
            stored as readonly LeftColumnSection[],
            canonical.filter((key) => key !== 'citation'),
        ),
        'citation',
    ];
}

export function normalizeIgsnColumnOrders(
    storedLeft: readonly TemplateSection[],
    storedRight: readonly TemplateSection[],
): { left: IgsnSection[]; right: IgsnSection[] } {
    const valid = new Set<IgsnSection>(IGSN_SECTIONS);
    const seen = new Set<IgsnSection>();
    const left: IgsnSection[] = [];
    const right: IgsnSection[] = [];

    const appendKnown = (target: IgsnSection[], values: readonly TemplateSection[]) => {
        for (const value of values) {
            const section = value as IgsnSection;
            if (!valid.has(section) || seen.has(section)) continue;
            seen.add(section);
            target.push(section);
        }
    };

    appendKnown(left, storedLeft);
    appendKnown(right, storedRight);
    const hasStoredCitation = seen.has('citation');
    appendKnown(left, hasStoredCitation ? IGSN_LEFT_COLUMN_SECTIONS : IGSN_LEFT_COLUMN_SECTIONS.filter((section) => section !== 'citation'));
    if (!hasStoredCitation) {
        seen.add('citation');
        left.push('citation');
    }
    for (const section of IGSN_RIGHT_COLUMN_SECTIONS) {
        if (seen.has(section)) continue;
        seen.add(section);
        const locationIndex = section === 'sample_image' ? right.indexOf('location') : -1;
        if (locationIndex === -1) right.push(section);
        else right.splice(locationIndex, 0, section);
    }

    return { left, right };
}
