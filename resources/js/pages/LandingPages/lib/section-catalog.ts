import type {
    IgsnSection,
    LandingPageTemplateConfig,
    LeftColumnSection,
    ResourceSection,
    RightColumnSection,
    TemplateSection,
} from '@/types/landing-page';

import { DESCRIPTION_SECTION_KEYS, LEGACY_DESCRIPTIONS_SECTION_KEY } from './metadata-sections';

type CanonicalRightColumnSection = Exclude<RightColumnSection, 'descriptions'>;

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
    licenses: 'License & Rights',
    general: 'General',
    sample_family: 'Sample Family',
    acquisition: 'Acquisition',
    igsn_methods: 'IGSN Methods',
    igsn_drilling: 'Drilling',
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
    igsn_methods: 'IGSN Methods',
    igsn_drilling: 'Drilling',
    repositories: 'Repositories',
    licenses: 'License & Rights',
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

export const RIGHT_COLUMN_SECTIONS: CanonicalRightColumnSection[] = [
    ...DESCRIPTION_SECTION_KEYS,
    'creators',
    'contributors',
    'funders',
    'keywords',
    'metadata_download',
    'location',
];

export const RESOURCE_LEFT_COLUMN_SECTIONS: Array<Extract<ResourceSection, LeftColumnSection>> = [
    'files',
    'licenses',
    'citation',
    'dates',
    'contact',
    'model_description',
    'related_work',
];

export const RESOURCE_METADATA_SECTIONS: ResourceSection[] = [
    ...DESCRIPTION_SECTION_KEYS,
    'creators',
    'contributors',
    'funders',
    'keywords',
    'metadata_download',
];

export const RESOURCE_SECTIONS: ResourceSection[] = [
    ...(RESOURCE_LEFT_COLUMN_SECTIONS as ResourceSection[]),
    ...(RIGHT_COLUMN_SECTIONS as ResourceSection[]),
];

export const IGSN_LEFT_COLUMN_SECTIONS: LeftColumnSection[] = [
    'general',
    'sample_family',
    'acquisition',
    'igsn_methods',
    'igsn_drilling',
    'repositories',
    'licenses',
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
    'licenses',
    'general',
    'sample_family',
    'acquisition',
    'igsn_methods',
    'igsn_drilling',
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

    const licenses = 'licenses' as T;
    if (canonicalSet.has(licenses) && !seen.has(licenses)) {
        const resourceAnchor = 'files' as T;
        const igsnAnchor = 'repositories' as T;
        const citation = 'citation' as T;
        const anchor = canonicalSet.has(resourceAnchor) ? resourceAnchor : igsnAnchor;
        const anchorIndex = result.indexOf(anchor);
        const citationIndex = result.indexOf(citation);
        const insertAt = anchorIndex !== -1 ? anchorIndex + 1 : citationIndex !== -1 ? citationIndex : result.length;

        result.splice(insertAt, 0, licenses);
        seen.add(licenses);
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

            return RIGHT_COLUMN_SECTIONS.includes(key as CanonicalRightColumnSection);
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

        if (!RIGHT_COLUMN_SECTIONS.includes(key as CanonicalRightColumnSection) || seen.has(key as RightColumnSection)) {
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

function groupResourceMetadataSections(order: ResourceSection[]): ResourceSection[] {
    const metadataSet = new Set<ResourceSection>(RESOURCE_METADATA_SECTIONS);
    const metadata: ResourceSection[] = [];
    const standalone: ResourceSection[] = [];
    let insertAt: number | null = null;

    for (const section of order) {
        if (metadataSet.has(section)) {
            insertAt ??= standalone.length;
            metadata.push(section);
        } else {
            standalone.push(section);
        }
    }

    if (metadata.length > 0) {
        standalone.splice(insertAt ?? standalone.length, 0, ...metadata);
    }

    return standalone;
}

export function normalizeResourceColumnOrders(
    storedLeft: readonly TemplateSection[],
    storedRight: readonly TemplateSection[],
): { left: ResourceSection[]; right: ResourceSection[] } {
    const valid = new Set<ResourceSection>(RESOURCE_SECTIONS);
    const seen = new Set<ResourceSection>();
    const left: ResourceSection[] = [];
    const right: ResourceSection[] = [];

    const appendKnown = (target: ResourceSection[], values: readonly TemplateSection[]) => {
        for (const value of values) {
            const sections = value === LEGACY_DESCRIPTIONS_SECTION_KEY ? DESCRIPTION_SECTION_KEYS : [value];

            for (const candidate of sections) {
                const section = candidate as ResourceSection;
                if (!valid.has(section) || seen.has(section)) continue;
                seen.add(section);
                target.push(section);
            }
        }
    };

    appendKnown(left, storedLeft);
    appendKnown(right, storedRight);
    const hasStoredCitation = seen.has('citation');

    if (!seen.has('licenses')) {
        const filesIndex = left.indexOf('files');
        const citationIndex = left.indexOf('citation');
        const insertAt = filesIndex !== -1 ? filesIndex + 1 : citationIndex !== -1 ? citationIndex : left.length;
        left.splice(insertAt, 0, 'licenses');
        seen.add('licenses');
    }

    appendKnown(left, hasStoredCitation ? RESOURCE_LEFT_COLUMN_SECTIONS : RESOURCE_LEFT_COLUMN_SECTIONS.filter((section) => section !== 'citation'));
    if (!hasStoredCitation) {
        seen.add('citation');
        left.push('citation');
    }
    appendKnown(right, RIGHT_COLUMN_SECTIONS);

    return {
        left: groupResourceMetadataSections(left),
        right: groupResourceMetadataSections(right),
    };
}
