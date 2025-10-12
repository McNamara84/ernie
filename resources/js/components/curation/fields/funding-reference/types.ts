export interface FundingReferenceEntry {
    id: string;
    funderName: string;
    funderIdentifier: string;
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
export const FUNDER_IDENTIFIER_TYPE = 'ROR'; // Hardcoded as per requirements
