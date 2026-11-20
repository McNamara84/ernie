import type { RelatedIdentifier } from '@/types';

/** Editor identity is separate from both storage IDs and editable identifiers. */
export interface RelatedWorkEditorEntry {
    clientId: string;
    data: RelatedIdentifier;
}

export function createRelatedWorkEditorEntry(data: RelatedIdentifier): RelatedWorkEditorEntry {
    return { clientId: crypto.randomUUID(), data };
}
