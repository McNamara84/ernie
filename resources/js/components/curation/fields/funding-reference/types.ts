export interface FundingReferenceEntry {
    id: string;
    funderName: string;
    funderIdentifier: string;
    funderIdentifierType: string | null; // 'ROR', 'Crossref Funder ID', 'ISNI', 'GRID', 'Other'
    awardNumber: string;
    awardUri: string;
    awardTitle: string;
    isExpanded: boolean; // UI state for progressive disclosure
}

export interface RorFunder {
    prefLabel: string;
    rorId: string;
    otherLabel: string[];
}

export const MAX_FUNDING_REFERENCES = 99;

// DataCite 4.6 supported funder identifier types
export const FUNDER_IDENTIFIER_TYPES = {
    ROR: 'ROR',
    CROSSREF: 'Crossref Funder ID',
    ISNI: 'ISNI',
    GRID: 'GRID',
    OTHER: 'Other',
} as const;

export type FunderIdentifierType = typeof FUNDER_IDENTIFIER_TYPES[keyof typeof FUNDER_IDENTIFIER_TYPES];
