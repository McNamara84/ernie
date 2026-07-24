export const CURATION_ACCORDION_ITEM_VALUES = [
    'licenses-rights',
    'authors',
    'contributors',
    'descriptions',
    'controlled-vocabularies',
    'free-keywords',
    'msl-laboratories',
    'spatial-temporal-coverage',
    'dates',
    'related-work',
    'citations',
    'used-instruments',
    'funding-references',
] as const;

export type CurationAccordionItemValue = (typeof CURATION_ACCORDION_ITEM_VALUES)[number];

export function isCurationAccordionItemValue(value: string): value is CurationAccordionItemValue {
    return (CURATION_ACCORDION_ITEM_VALUES as readonly string[]).includes(value);
}

export const DEFAULT_OPEN_ACCORDION_ITEMS = [
    'authors',
    'licenses-rights',
    'contributors',
    'descriptions',
    'controlled-vocabularies',
    'free-keywords',
    'spatial-temporal-coverage',
    'dates',
    'related-work',
    'citations',
    'funding-references',
    'used-instruments',
] as const satisfies readonly CurationAccordionItemValue[];
