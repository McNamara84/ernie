import { ChevronDown, ChevronRight, GripVertical, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import InputField from '../input-field';
import { searchRorFunders } from './ror-search';
import type { FundingReferenceEntry, RorFunder } from './types';

interface FundingReferenceItemProps {
    funding: FundingReferenceEntry;
    index: number;
    onFunderNameChange: (value: string) => void;
    onFunderIdentifierChange: (value: string) => void;
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
    onFunderIdentifierChange,
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

    // Debounced search
    useEffect(() => {
        if (!funding.funderName || funding.funderName.length < 2) {
            setFilteredSuggestions([]);
            return;
        }

        const timeoutId = setTimeout(() => {
            const results = searchRorFunders(rorFunders, funding.funderName, 10);
            setFilteredSuggestions(results);
            setShowSuggestions(results.length > 0);
        }, 300);

        return () => clearTimeout(timeoutId);
    }, [funding.funderName, rorFunders]);

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
            onFunderNameChange(suggestion.prefLabel);
            onFunderIdentifierChange(suggestion.rorId);
            setShowSuggestions(false);
            setFilteredSuggestions([]);
        },
        [onFunderNameChange, onFunderIdentifierChange]
    );

    return (
        <section
            className="rounded-lg border border-border bg-card p-6 shadow-sm transition hover:shadow-md"
            aria-labelledby={`${funding.id}-heading`}
        >
            {/* Header */}
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    {/* Drag Handle - will be added with @dnd-kit */}
                    <div
                        className="cursor-grab active:cursor-grabbing opacity-50"
                        aria-label="Drag to reorder"
                    >
                        <GripVertical className="h-5 w-5 text-muted-foreground" />
                    </div>

                    {/* Title */}
                    <h3
                        id={`${funding.id}-heading`}
                        className="text-lg font-semibold leading-6 text-foreground"
                    >
                        Funding #{index + 1}
                    </h3>
                </div>

                {/* Remove Button */}
                {canRemove && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={onRemove}
                        aria-label={`Remove funding ${index + 1}`}
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {/* Funder Name (Required) with Autocomplete */}
            <div className="mt-6 space-y-4">
                <div className="relative">
                    <Label htmlFor={`${funding.id}-funder-name`}>
                        Funder Name <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        ref={inputRef}
                        id={`${funding.id}-funder-name`}
                        value={funding.funderName}
                        onChange={(e) => onFunderNameChange(e.target.value)}
                        onFocus={() => {
                            if (filteredSuggestions.length > 0) {
                                setShowSuggestions(true);
                            }
                        }}
                        placeholder="e.g., Deutsche Forschungsgemeinschaft (DFG)"
                        required
                        className="mt-2"
                        autoComplete="off"
                    />

                    {/* Autocomplete Dropdown */}
                    {showSuggestions && filteredSuggestions.length > 0 && (
                        <div
                            ref={suggestionsRef}
                            className="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border border-border bg-popover text-popover-foreground shadow-md"
                            role="listbox"
                        >
                            {filteredSuggestions.map((suggestion) => (
                                <button
                                    key={suggestion.rorId}
                                    type="button"
                                    onClick={() => handleSelectSuggestion(suggestion)}
                                    className="flex w-full cursor-pointer flex-col gap-1 border-b border-border px-4 py-3 text-left transition hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:text-accent-foreground focus:outline-none last:border-b-0"
                                    role="option"
                                    aria-selected={false}
                                    tabIndex={0}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            e.preventDefault();
                                            handleSelectSuggestion(suggestion);
                                        }
                                    }}
                                >
                                    <div className="font-medium">{suggestion.prefLabel}</div>
                                    {suggestion.otherLabel && (
                                        <div className="text-xs text-muted-foreground">
                                            {suggestion.otherLabel}
                                        </div>
                                    )}
                                    <div className="text-xs text-muted-foreground">
                                        🏛️ {suggestion.rorId}
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* ROR ID Badge (if available) */}
                {funding.funderIdentifier && (
                    <div className="flex items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            🏛️ ROR: {funding.funderIdentifier}
                        </Badge>
                    </div>
                )}

                {/* Toggle Award Details */}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onToggleExpanded}
                    className="gap-2"
                >
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
                    <div className="space-y-4 rounded-lg border border-border bg-muted/30 p-4">
                        <InputField
                            id={`${funding.id}-award-number`}
                            label="Award/Grant Number"
                            value={funding.awardNumber}
                            onChange={(e) => onAwardNumberChange(e.target.value)}
                            placeholder="e.g., ERC-2021-STG-101234567"
                        />

                        <InputField
                            id={`${funding.id}-award-uri`}
                            label="Award URI"
                            value={funding.awardUri}
                            onChange={(e) => onAwardUriChange(e.target.value)}
                            placeholder="e.g., https://cordis.europa.eu/project/id/101234567"
                            type="url"
                        />

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
