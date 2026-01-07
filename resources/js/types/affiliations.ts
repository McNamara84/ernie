export interface AffiliationTag {
    value: string;
    rorId: string | null;
    [key: string]: unknown;
}

export interface AffiliationSuggestion extends AffiliationTag {
    searchTerms: string[];
    country?: string | null;
}
