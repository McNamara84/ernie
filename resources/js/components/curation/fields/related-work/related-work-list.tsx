import type { RelatedIdentifier } from '@/types';

import RelatedWorkItem from './related-work-item';
import type { RelatedWorkEditorEntry } from './types';

export interface CitationResolutionState {
    status: 'resolving' | 'resolved' | 'unavailable';
    message?: string;
}

interface RelatedWorkListProps {
    entries: RelatedWorkEditorEntry[];
    completedItemCount?: number;
    onItemChange: (clientId: string, item: RelatedIdentifier) => void;
    onIdentifierBlur?: (clientId: string) => void;
    onRemove: (clientId: string) => void;
    activeRelationTypes?: string[];
    activeIdentifierTypes?: string[];
    validationStatuses?: Map<string, { status: 'validating' | 'valid' | 'invalid' | 'warning'; message?: string }>;
    citationResolutionStates?: Map<string, CitationResolutionState>;
}

/** Render the session's display order while addressing edits by stable identity. */
export default function RelatedWorkList({
    entries,
    completedItemCount = entries.filter(({ data }) => data.identifier.trim() !== '').length,
    onItemChange,
    onIdentifierBlur,
    onRemove,
    activeRelationTypes,
    activeIdentifierTypes,
    validationStatuses,
    citationResolutionStates,
}: RelatedWorkListProps) {
    if (entries.length === 0) return null;

    return (
        <div className="space-y-3">
            <h4 className="text-sm font-medium text-foreground">Added Relations ({completedItemCount})</h4>
            <div className="space-y-3" role="list" aria-label="Related works">
                {entries.map(({ clientId, data }, index) => {
                    const validation = validationStatuses?.get(clientId);
                    const citationResolution = citationResolutionStates?.get(clientId);

                    return (
                        <RelatedWorkItem
                            key={clientId}
                            item={data}
                            index={index}
                            onChange={(updatedItem) => onItemChange(clientId, updatedItem)}
                            onIdentifierBlur={() => onIdentifierBlur?.(clientId)}
                            onRemove={() => onRemove(clientId)}
                            activeRelationTypes={activeRelationTypes}
                            activeIdentifierTypes={activeIdentifierTypes}
                            validationStatus={validation?.status}
                            validationMessage={validation?.message}
                            citationResolutionStatus={citationResolution?.status}
                            citationResolutionMessage={citationResolution?.message}
                        />
                    );
                })}
            </div>
        </div>
    );
}
