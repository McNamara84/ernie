import { AlertCircle, ChevronDown, ChevronRight, Trash2 } from 'lucide-react';
import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFundingReferenceValidation } from '@/hooks/use-funding-reference-validation';
import { useRorInputDraft } from '@/hooks/use-ror-input-drafts';
import { indexRorSuggestions, parseRorInput, resolveRorInput } from '@/lib/ror-input';
import { cn } from '@/lib/utils';
import type { AffiliationTag } from '@/types/affiliations';

import InputField from '../input-field';
import { RorCatalogContext, RorInputFeedback } from '../ror-input-feedback';
import { searchRorFunders } from './ror-search';
import type { FundingReferenceEntry, RorFunder } from './types';

interface FundingReferenceItemProps {
    funding: FundingReferenceEntry;
    index: number;
    onFunderNameChange: (value: string) => void;
    onFieldsChange: (fields: Partial<FundingReferenceEntry>) => void;
    onAwardNumberChange: (value: string) => void;
    onAwardUriChange: (value: string) => void;
    onAwardTitleChange: (value: string) => void;
    onToggleExpanded: () => void;
    onRemove: () => void;
    canRemove: boolean;
    rorFunders: RorFunder[];
}

export function FundingReferenceItem({
    funding,
    index,
    onFunderNameChange,
    onFieldsChange,
    onAwardNumberChange,
    onAwardUriChange,
    onAwardTitleChange,
    onToggleExpanded,
    onRemove,
    canRemove,
    rorFunders,
}: FundingReferenceItemProps) {
    const [showSuggestions, setShowSuggestions] = useState(false);
    const [filteredSuggestions, setFilteredSuggestions] = useState<RorFunder[]>([]);
    const inputRef = useRef<HTMLInputElement>(null);
    const suggestionsRef = useRef<HTMLDivElement>(null);
    const inputId = `${funding.id}-funder-name`;
    const draft = useRorInputDraft(inputId);
    const catalog = useContext(RorCatalogContext);
    const rorIndex = useMemo(
        () =>
            catalog?.index ??
            indexRorSuggestions(rorFunders.map((item) => ({ value: item.prefLabel, rorId: item.rorId, searchTerms: item.otherLabel }))),
        [catalog?.index, rorFunders],
    );
    const funderName = draft.draft?.text ?? funding.funderName;
    const rorInput = parseRorInput(draft.draft?.text ?? '');
    const [activeSuggestion, setActiveSuggestion] = useState(0);

    // Validation
    const validation = useFundingReferenceValidation(funding);

    // Debounced search
    useEffect(() => {
        // Don't show suggestions if a ROR ID is already selected
        if ((funding.funderIdentifier && !draft.draft) || parseRorInput(funderName).kind !== 'name') {
            setFilteredSuggestions([]);
            return;
        }

        if (!funderName || funderName.length < 2) {
            setFilteredSuggestions([]);
            return;
        }

        const timeoutId = setTimeout(() => {
            const results = searchRorFunders(rorFunders, funderName, 20);
            setFilteredSuggestions(results);
            setShowSuggestions(results.length > 0);
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [funderName, funding.funderIdentifier, rorFunders, draft.draft]);

    // Click outside handler
    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (
                suggestionsRef.current &&
                !suggestionsRef.current.contains(event.target as Node) &&
                inputRef.current &&
                !inputRef.current.contains(event.target as Node)
            ) {
                setShowSuggestions(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleSelectSuggestion = useCallback(
        (suggestion: RorFunder) => {
            draft.set(null);
            // Atomic update of both fields to prevent race condition
            onFieldsChange({
                funderName: suggestion.prefLabel,
                funderIdentifier: suggestion.rorId,
                funderIdentifierType: 'ROR',
            });
            setShowSuggestions(false);
            setFilteredSuggestions([]);
            // Blur the input to ensure the value is updated
            if (inputRef.current) {
                inputRef.current.blur();
            }
        },
        [onFieldsChange, draft],
    );

    const handleFunderNameChange = useCallback(
        (value: string) => {
            // Clear ROR ID and type when user manually edits the funder name
            if (funding.funderIdentifier) {
                onFieldsChange({
                    funderName: value,
                    funderIdentifier: '',
                    funderIdentifierType: null,
                });
            } else {
                onFunderNameChange(value);
            }
        },
        [funding.funderIdentifier, onFieldsChange, onFunderNameChange],
    );

    const selectRor = (selected: AffiliationTag) => {
        draft.set(null);
        onFieldsChange({ funderName: selected.value, funderIdentifier: selected.rorId ?? '', funderIdentifierType: selected.rorId ? 'ROR' : null });
        setShowSuggestions(false);
        setFilteredSuggestions([]);
    };
    const commitName = () => {
        const pending = draft.get();
        if (pending && parseRorInput(pending.text).kind === 'name') {
            handleFunderNameChange(pending.text);
            draft.set(null);
        }
    };
    const rorMatch = resolveRorInput(rorInput, rorIndex);
    const hasRorOption = !!rorMatch && !catalog?.isLoading && !catalog?.error;

    return (
        <section
            className="rounded-lg border border-l-4 border-border border-l-gfz-primary bg-card p-6 text-card-foreground shadow-md transition-[border-color,box-shadow] focus-within:border-ring focus-within:border-l-gfz-primary focus-within:ring-[3px] focus-within:ring-ring/50 hover:border-ring/70 hover:border-l-gfz-primary hover:shadow-lg"
            aria-labelledby={`${funding.id}-heading`}
            data-testid="funding-reference-card"
        >
            {/* Header */}
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    {/* Title */}
                    <h3 id={`${funding.id}-heading`} className="text-lg leading-6 font-semibold text-foreground">
                        Funding #{index + 1}
                    </h3>
                </div>

                {/* Remove Button */}
                {canRemove && (
                    <Button type="button" variant="outline" size="icon" onClick={onRemove} aria-label={`Remove funding ${index + 1}`}>
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {/* Funder Name (Required) with Autocomplete */}
            <div className="mt-6 space-y-4">
                <div className="relative">
                    <Label htmlFor={`${funding.id}-funder-name`}>
                        Funder Name <span className="font-bold text-destructive">*</span>
                    </Label>
                    <Input
                        ref={inputRef}
                        id={`${funding.id}-funder-name`}
                        value={funderName}
                        onChange={(event) => {
                            draft.set({ text: event.target.value, section: 'fundingReferences' });
                            setShowSuggestions(true);
                            setActiveSuggestion(0);
                        }}
                        onBlur={commitName}
                        role="combobox"
                        aria-expanded={showSuggestions && (hasRorOption || filteredSuggestions.length > 0)}
                        aria-controls={rorInput.kind !== 'name' ? `${inputId}-ror-options` : `${inputId}-options`}
                        aria-activedescendant={
                            showSuggestions
                                ? hasRorOption
                                    ? `${inputId}-ror-option`
                                    : filteredSuggestions.length
                                      ? `${inputId}-option-${activeSuggestion}`
                                      : undefined
                                : undefined
                        }
                        onKeyDown={(event) => {
                            if (event.nativeEvent.isComposing) return;
                            if (event.key === 'Escape') {
                                setShowSuggestions(false);
                                event.preventDefault();
                                return;
                            }
                            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                event.preventDefault();
                                setShowSuggestions(true);
                                setActiveSuggestion((current) =>
                                    Math.max(0, Math.min(filteredSuggestions.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1))),
                                );
                            }
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                if (showSuggestions && hasRorOption && rorMatch) selectRor(rorMatch);
                                else if (showSuggestions && filteredSuggestions[activeSuggestion])
                                    handleSelectSuggestion(filteredSuggestions[activeSuggestion]);
                                else commitName();
                            }
                        }}
                        onFocus={() => {
                            if (filteredSuggestions.length > 0) {
                                setShowSuggestions(true);
                            }
                        }}
                        placeholder="e.g., Deutsche Forschungsgemeinschaft (DFG)"
                        required
                        className={cn('mt-2', validation.errors.funderName && 'border-destructive focus-visible:ring-destructive')}
                        autoComplete="off"
                        aria-invalid={!!validation.errors.funderName}
                        aria-describedby={[
                            validation.errors.funderName ? `${funding.id}-funder-name-error` : '',
                            `${inputId}-help`,
                            rorInput.kind !== 'name' ? `${inputId}-ror-feedback` : '',
                        ]
                            .filter(Boolean)
                            .join(' ')}
                    />
                    <p id={`${inputId}-help`} className="mt-1 text-xs text-muted-foreground">
                        Search by name, ROR ID, URL or Name (ROR ID), then select a match.
                    </p>
                    <RorInputFeedback
                        {...catalog}
                        id={inputId}
                        text={draft.draft?.text ?? ''}
                        index={rorIndex}
                        open={showSuggestions}
                        onSelect={selectRor}
                        onDiscard={() => {
                            draft.set(null);
                            setShowSuggestions(false);
                        }}
                    />

                    {/* Validation Error */}
                    {validation.errors.funderName && (
                        <p id={`${funding.id}-funder-name-error`} className="mt-1 flex items-center gap-1 text-xs text-destructive">
                            <AlertCircle className="h-3 w-3" />
                            {validation.errors.funderName}
                        </p>
                    )}

                    {/* Autocomplete Dropdown */}
                    {showSuggestions && filteredSuggestions.length > 0 && (
                        <div
                            ref={suggestionsRef}
                            className="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border border-border bg-popover text-popover-foreground shadow-md"
                            role="listbox"
                            id={`${inputId}-options`}
                        >
                            {filteredSuggestions.map((suggestion, suggestionIndex) => (
                                <button
                                    key={suggestion.rorId}
                                    type="button"
                                    id={`${inputId}-option-${suggestionIndex}`}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        handleSelectSuggestion(suggestion);
                                    }}
                                    className="flex w-full cursor-pointer flex-col gap-1 border-b border-border px-4 py-3 text-left transition last:border-b-0 hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground focus:outline-none"
                                    role="option"
                                    aria-selected={activeSuggestion === suggestionIndex}
                                    tabIndex={0}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            e.preventDefault();
                                            handleSelectSuggestion(suggestion);
                                        }
                                    }}
                                >
                                    <div className="font-medium">{suggestion.prefLabel}</div>
                                    {suggestion.otherLabel && <div className="text-xs text-muted-foreground">{suggestion.otherLabel}</div>}
                                    <div className="text-xs text-muted-foreground">🏛️ {suggestion.rorId}</div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Funder Identifier Badge (ROR, Crossref Funder ID, etc.) */}
                {funding.funderIdentifier && funding.funderIdentifierType && (
                    <div className="flex items-center gap-2">
                        <a href={funding.funderIdentifier} target="_blank" rel="noopener noreferrer" className="inline-flex">
                            <Badge
                                variant="outline"
                                className="cursor-pointer text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                            >
                                {funding.funderIdentifierType === 'ROR' && '🏛️ '}
                                {funding.funderIdentifierType === 'Crossref Funder ID' && '🔗 '}
                                {funding.funderIdentifierType === 'ISNI' && '📇 '}
                                {funding.funderIdentifierType === 'GRID' && '🌐 '}
                                {funding.funderIdentifierType === 'Other' && '🏷️ '}
                                {funding.funderIdentifierType}: {funding.funderIdentifier}
                            </Badge>
                        </a>
                    </div>
                )}

                {/* Toggle Award Details */}
                <Button type="button" variant="ghost" size="sm" onClick={onToggleExpanded} className="gap-2">
                    {funding.isExpanded ? (
                        <>
                            <ChevronDown className="h-4 w-4" />
                            Hide award details
                        </>
                    ) : (
                        <>
                            <ChevronRight className="h-4 w-4" />
                            Show award details
                        </>
                    )}
                </Button>

                {/* Award Details (Expanded) */}
                {funding.isExpanded && (
                    <div
                        className="space-y-4 rounded-lg border border-dashed border-input bg-background p-4"
                        data-testid="funding-reference-award-details"
                    >
                        <InputField
                            id={`${funding.id}-award-number`}
                            label="Award/Grant Number"
                            value={funding.awardNumber}
                            onChange={(e) => onAwardNumberChange(e.target.value)}
                            placeholder="e.g., ERC-2021-STG-101234567"
                        />

                        <div className="space-y-2">
                            <Label htmlFor={`${funding.id}-award-uri`}>Award URI</Label>
                            <Input
                                id={`${funding.id}-award-uri`}
                                type="url"
                                value={funding.awardUri}
                                onChange={(e) => onAwardUriChange(e.target.value)}
                                placeholder="e.g., https://cordis.europa.eu/project/id/101234567"
                                className={cn(validation.errors.awardUri && 'border-destructive focus-visible:ring-destructive')}
                                aria-invalid={!!validation.errors.awardUri}
                                aria-describedby={validation.errors.awardUri ? `${funding.id}-award-uri-error` : undefined}
                            />
                            {validation.errors.awardUri && (
                                <p id={`${funding.id}-award-uri-error`} className="flex items-center gap-1 text-xs text-destructive">
                                    <AlertCircle className="h-3 w-3" />
                                    {validation.errors.awardUri}
                                </p>
                            )}
                        </div>

                        <InputField
                            id={`${funding.id}-award-title`}
                            label="Award Title"
                            value={funding.awardTitle}
                            onChange={(e) => onAwardTitleChange(e.target.value)}
                            placeholder="e.g., Innovative Research in AI Systems"
                        />
                    </div>
                )}
            </div>
        </section>
    );
}
