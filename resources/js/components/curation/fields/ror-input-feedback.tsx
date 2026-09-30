import { createContext } from 'react';

import { Button } from '@/components/ui/button';
import { parseRorInput, resolveRorInput } from '@/lib/ror-input';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

export interface RorCatalogState {
    isLoading?: boolean;
    error?: Error | null;
    retry?: () => void;
}

export const RorCatalogContext = createContext<
    (RorCatalogState & { index: ReadonlyMap<string, AffiliationSuggestion>; suggestions: AffiliationSuggestion[] }) | null
>(null);

interface Props extends RorCatalogState {
    id: string;
    text: string;
    index: ReadonlyMap<string, AffiliationSuggestion>;
    open: boolean;
    onSelect: (value: AffiliationTag) => void;
    onDiscard: () => void;
}

export function RorInputFeedback({ id, text, index, open, isLoading, error, retry, onSelect, onDiscard }: Props) {
    const input = parseRorInput(text);
    if (input.kind === 'name') return null;
    const match = resolveRorInput(input, index);
    const message =
        input.kind === 'invalid'
            ? 'Enter a valid ROR ID or ROR URL.'
            : isLoading
              ? 'Loading ROR data…'
              : error || index.size === 0
                ? 'The local ROR directory is unavailable.'
                : !match
                  ? 'ROR ID not found in the local directory.'
                  : 'Select the organization to confirm its ROR ID.';

    return (
        <div className="mt-2 space-y-2 text-sm" id={`${id}-ror-feedback`}>
            <p role="status" className="text-muted-foreground">
                {message}
            </p>
            {open && match && !isLoading && !error && (
                <div id={`${id}-ror-options`} role="listbox" aria-label="ROR organization">
                    <button
                        type="button"
                        role="option"
                        aria-selected="true"
                        id={`${id}-ror-option`}
                        className="flex w-full flex-col rounded-md border bg-popover p-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => onSelect(match)}
                    >
                        <span>Use name: {match.value}</span>
                        <span className="text-muted-foreground">ROR record: {input.kind === 'ror' && index.get(input.rorId)?.value}</span>
                        <span className="font-mono text-xs">{match.rorId}</span>
                    </button>
                </div>
            )}
            {!isLoading && (!match || error) && input.name && (
                <Button type="button" variant="outline" size="sm" onClick={() => onSelect({ value: input.name, rorId: null })}>
                    Use name without ROR ID
                </Button>
            )}
            {!isLoading && (error || index.size === 0) && retry && (
                <Button type="button" variant="outline" size="sm" onClick={retry}>
                    Reload ROR directory
                </Button>
            )}
            <Button type="button" variant="ghost" size="sm" onClick={onDiscard}>
                Discard ROR input
            </Button>
        </div>
    );
}
