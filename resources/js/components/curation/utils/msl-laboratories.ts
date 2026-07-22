import type { MSLLaboratory, MSLLaboratoryVocabularyEntry } from '@/types';

const FREE_KEYWORD_TRIGGERS = new Set(['epos', 'multi-scale laboratories']);

/**
 * Whether the editor should unlock MSL-specific controls.
 *
 * Free keywords deliberately use exact matching after trimming and case
 * normalisation so unrelated words such as "depositional" cannot trigger the
 * section. Controlled MSL vocabulary selections remain an independent trigger.
 */
export function hasMslLaboratoryTrigger(freeKeywords: readonly string[], hasControlledMslKeyword = false): boolean {
    if (hasControlledMslKeyword) {
        return true;
    }

    return freeKeywords.some((keyword) => FREE_KEYWORD_TRIGGERS.has(keyword.trim().toLocaleLowerCase('en-US')));
}

/** Keep only the four fields that are persisted with the resource. */
export function toMslLaboratorySelection(laboratory: MSLLaboratoryVocabularyEntry): MSLLaboratory {
    return {
        identifier: laboratory.identifier,
        name: laboratory.name,
        affiliation_name: laboratory.affiliation_name,
        affiliation_ror: getValidRorUrl(laboratory.affiliation_ror),
    };
}

export function getValidRorUrl(value: string | null): string | null {
    if (!value || !/^https:\/\/ror\.org\/[0-9a-z]{9}\/?$/i.test(value)) {
        return null;
    }

    return value.replace(/\/$/, '');
}
