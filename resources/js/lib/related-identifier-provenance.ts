export const RELATED_IDENTIFIER_SOURCE_RELATION_SUGGESTION_ASSISTANT = 'relation_suggestion_assistant' as const;

interface RelatedIdentifierProvenance {
    source?: string | null;
    is_repository_curation?: boolean | null;
}

export function isRepositoryCurationRelatedIdentifier(relatedIdentifier: RelatedIdentifierProvenance): boolean {
    return relatedIdentifier.is_repository_curation === true || relatedIdentifier.source === RELATED_IDENTIFIER_SOURCE_RELATION_SUGGESTION_ASSISTANT;
}
