import { Info, Lightbulb, Plus } from 'lucide-react';
import { useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useIdentifierValidation } from '@/hooks/use-identifier-validation';
import { getOppositeRelationType, MOST_USED_RELATION_TYPES, RELATION_TYPE_DESCRIPTIONS } from '@/lib/related-identifiers';
import type { IdentifierType, RelatedIdentifierFormData, RelationType } from '@/types';

interface RelatedWorkQuickAddProps {
    onAdd: (data: RelatedIdentifierFormData) => void;
    showAdvancedMode?: boolean;
    onToggleAdvanced?: () => void;
    identifier: string;
    onIdentifierChange: (value: string) => void;
    identifierType: IdentifierType;
    relationType: RelationType;
    onRelationTypeChange: (value: RelationType) => void;
}

/**
 * RelatedWorkQuickAdd Component
 *
 * Quick mode for adding related works with:
 * - Auto-detection of identifier type (DOI, URL)
 * - Top 5 most used relation types for quick access
 * - Bidirectional relation suggestions
 * - Real-time DOI validation with DataCite API
 * - Clean, focused UX
 */
export default function RelatedWorkQuickAdd({
    onAdd,
    showAdvancedMode = false,
    onToggleAdvanced,
    identifier,
    onIdentifierChange,
    identifierType,
    relationType,
    onRelationTypeChange,
}: RelatedWorkQuickAddProps) {
    const [showSuggestion, setShowSuggestion] = useState(false);

    // Validate identifier with API
    const validation = useIdentifierValidation({
        identifier,
        identifierType,
        enabled: identifier.trim().length > 0,
        debounceMs: 800,
    });

    const handleAdd = () => {
        if (!identifier.trim() || validation.status === 'invalid') {
            return;
        }

        let normalizedIdentifier = identifier.trim();

        // If DOI was entered with URL prefix, extract just the DOI part
        if (identifierType === 'DOI') {
            const doiUrlMatch = normalizedIdentifier.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
            if (doiUrlMatch) {
                normalizedIdentifier = doiUrlMatch[1];
            }
        }

        onAdd({
            identifier: normalizedIdentifier,
            identifierType,
            relationType,
        });

        // Form reset is handled by parent component
        setShowSuggestion(false);
    };

    const handleKeyPress = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAdd();
        }
    };

    // Check for bidirectional suggestion
    const handleRelationTypeChange = (value: string) => {
        onRelationTypeChange(value as RelationType);
        const opposite = getOppositeRelationType(value as RelationType);
        setShowSuggestion(!!opposite);
    };

    const oppositeRelation = getOppositeRelationType(relationType);

    // Quick suggestion for opposite relation
    const handleUseSuggestion = () => {
        if (oppositeRelation) {
            onRelationTypeChange(oppositeRelation);
            setShowSuggestion(false);
        }
    };

    return (
        <div className="space-y-4">
            {/* Info text */}
            <div className="flex items-start gap-2 text-sm text-muted-foreground">
                <Info className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
                <p>
                    Add relationships to other datasets, publications, or resources. Enter a DOI, URL, or other identifier and select the relationship
                    type.
                </p>
            </div>

            {/* Quick Add Form */}
            <div className="grid gap-4 md:grid-cols-12">
                {/* Identifier Input */}
                <div className="md:col-span-6">
                    <Label htmlFor="related-identifier" className="sr-only">
                        Identifier (DOI, URL, etc.)
                    </Label>
                    <div className="relative">
                        <Input
                            id="related-identifier"
                            data-testid="related-identifier-input"
                            type="text"
                            value={identifier}
                            onChange={(e) => {
                                onIdentifierChange(e.target.value);
                                setShowSuggestion(false);
                            }}
                            onKeyPress={handleKeyPress}
                            placeholder="e.g., 10.5194/nhess-15-1463-2015 or https://..."
                            className={`font-mono text-sm ${
                                validation.status === 'invalid'
                                    ? 'border-red-500 focus-visible:ring-red-500'
                                    : validation.status === 'valid'
                                      ? 'border-green-500 focus-visible:ring-green-500'
                                      : validation.status === 'warning'
                                        ? 'border-yellow-500 focus-visible:ring-yellow-500'
                                        : ''
                            }`}
                        />
                        {validation.status === 'validating' && (
                            <div className="absolute top-1/2 right-3 -translate-y-1/2">
                                <Spinner size="sm" />
                            </div>
                        )}
                    </div>
                    {validation.status === 'invalid' && <p className="mt-1 text-xs text-red-600">Invalid {identifierType} format</p>}
                    {validation.status === 'warning' && (
                        <p className="mt-1 text-xs text-yellow-600">
                            <Info className="mr-1 inline h-3 w-3" />
                            Could not verify via API, but format is valid
                        </p>
                    )}
                    {validation.status === 'valid' && validation.metadata?.title && (
                        <p className="mt-1 text-xs text-green-600">✓ {validation.metadata.title}</p>
                    )}
                    {validation.status === 'idle' && (
                        <p className="mt-1 text-xs text-muted-foreground">Type will be auto-detected (DOI, URL, Handle)</p>
                    )}
                </div>

                {/* Relation Type Select */}
                <div className="md:col-span-5">
                    <Label htmlFor="relation-type" className="sr-only">
                        Relation Type
                    </Label>
                    <Select value={relationType} onValueChange={handleRelationTypeChange}>
                        <SelectTrigger id="relation-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground">Most Used</div>
                            {MOST_USED_RELATION_TYPES.map((type) => (
                                <SelectItem key={type} value={type}>
                                    <div className="flex flex-col items-start">
                                        <span>{type}</span>
                                        <span className="text-xs text-muted-foreground">{RELATION_TYPE_DESCRIPTIONS[type].substring(0, 50)}...</span>
                                    </div>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Add Button */}
                <div className="flex items-start md:col-span-1">
                    <Button
                        type="button"
                        onClick={handleAdd}
                        disabled={!identifier.trim() || validation.status === 'invalid' || validation.status === 'validating'}
                        size="icon"
                        aria-label="Add related work"
                        data-testid="add-related-work-button"
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            {/* Bidirectional Suggestion */}
            {showSuggestion && oppositeRelation && (
                <Alert className="border-blue-200 bg-blue-50">
                    <Lightbulb className="h-4 w-4 text-blue-600" />
                    <AlertDescription className="flex items-center justify-between">
                        <span className="text-sm">
                            Did you mean <strong>{oppositeRelation}</strong> instead?
                            <span className="ml-1 text-muted-foreground">({RELATION_TYPE_DESCRIPTIONS[oppositeRelation]})</span>
                        </span>
                        <Button type="button" variant="outline" size="sm" onClick={handleUseSuggestion} className="ml-4 flex-shrink-0">
                            Use {oppositeRelation}
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {/* Advanced Mode Toggle */}
            {onToggleAdvanced && (
                <div className="pt-2">
                    <Button type="button" variant="ghost" size="sm" onClick={onToggleAdvanced} className="text-xs">
                        {showAdvancedMode ? '← Simple mode' : 'Show all relation types →'}
                    </Button>
                </div>
            )}
        </div>
    );
}
