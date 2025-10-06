export interface AffiliationTag {
    value: string;
    rorId: string | null;
}

export interface AffiliationSuggestion extends AffiliationTag {
    searchTerms: string[];
}
