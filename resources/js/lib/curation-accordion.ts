export const CURATION_ACCORDION_ITEM_VALUES = [
    'resource-info',
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

export const DEFAULT_OPEN_ACCORDION_ITEMS = [
    'resource-info',
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
