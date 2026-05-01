import type { LandingPageDescription, RightColumnSection } from '@/types/landing-page';

export const LEGACY_DESCRIPTIONS_SECTION_KEY = 'descriptions' as const;

export const DESCRIPTION_SECTION_KEYS = [
    'abstract',
    'methods',
    'technical_info',
    'series_information',
    'table_of_contents',
    'other',
] as const;

export type DescriptionSectionKey = (typeof DESCRIPTION_SECTION_KEYS)[number];

export type MetadataSectionKey = Exclude<RightColumnSection, 'location'>;

export type ExpandedMetadataSectionKey = Exclude<MetadataSectionKey, typeof LEGACY_DESCRIPTIONS_SECTION_KEY>;

export const DESCRIPTION_SECTION_CONFIG: Record<DescriptionSectionKey, { heading: string; matches: string[] }> = {
    abstract: { heading: 'Abstract', matches: ['abstract'] },
    methods: { heading: 'Methods', matches: ['methods'] },
    technical_info: { heading: 'Technical Information', matches: ['technicalinfo', 'technicalinformation'] },
    series_information: { heading: 'Series Information', matches: ['seriesinformation'] },
    table_of_contents: { heading: 'Table of Contents', matches: ['tableofcontents'] },
    other: { heading: 'Other', matches: ['other'] },
};

export function normalizeDescriptionType(value: string | null | undefined): string {
    return (value ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

export function filterDescriptionsBySection(
    descriptions: LandingPageDescription[],
    sectionKey: DescriptionSectionKey,
): LandingPageDescription[] {
    const { matches } = DESCRIPTION_SECTION_CONFIG[sectionKey];

    return descriptions.filter((description) => matches.includes(normalizeDescriptionType(description.description_type)));
}

export function isDescriptionSectionKey(key: MetadataSectionKey | ExpandedMetadataSectionKey): key is DescriptionSectionKey {
    return DESCRIPTION_SECTION_KEYS.includes(key as DescriptionSectionKey);
}

export function expandMetadataOrder(sectionOrder: readonly MetadataSectionKey[]): ExpandedMetadataSectionKey[] {
    const expanded: ExpandedMetadataSectionKey[] = [];
    const seen = new Set<ExpandedMetadataSectionKey>();

    for (const key of sectionOrder) {
        const keysToInsert = key === LEGACY_DESCRIPTIONS_SECTION_KEY
            ? [...DESCRIPTION_SECTION_KEYS]
            : [key as ExpandedMetadataSectionKey];

        for (const expandedKey of keysToInsert) {
            if (seen.has(expandedKey)) {
                continue;
            }

            seen.add(expandedKey);
            expanded.push(expandedKey);
        }
    }

    return expanded;
}