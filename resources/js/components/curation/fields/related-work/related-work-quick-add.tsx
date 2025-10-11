import { Info, Lightbulb, Plus } from 'lucide-react';
import { useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useIdentifierValidation } from '@/hooks/use-identifier-validation';
import {
    getOppositeRelationType,
    MOST_USED_RELATION_TYPES,
    RELATION_TYPE_DESCRIPTIONS,
} from '@/lib/related-identifiers';
import type { IdentifierType, RelatedIdentifierFormData, RelationType } from '@/types';

interface RelatedWorkQuickAddProps {
    onAdd: (data: RelatedIdentifierFormData) => void;
    showAdvancedMode?: boolean;
    onToggleAdvanced?: () => void;
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
}: RelatedWorkQuickAddProps) {
    const [identifier, setIdentifier] = useState('');
    const [relationType, setRelationType] = useState<RelationType>('Cites');
    const [showSuggestion, setShowSuggestion] = useState(false);

    // Auto-detect identifier type
    const detectIdentifierType = (value: string): IdentifierType => {
        const trimmed = value.trim();
        
        // DOI with URL prefix (extract DOI part)
        // Matches: https://doi.org/10.xxxx/xxx or http://dx.doi.org/10.xxxx/xxx
        const doiUrlMatch = trimmed.match(/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i);
        if (doiUrlMatch) {
            return 'DOI';
        }
        
        // DOI patterns (without URL prefix)
        if (trimmed.match(/^10\.\d{4,}/)) {
            return 'DOI';
        }
        
        // URL patterns
        if (trimmed.match(/^https?:\/\//i)) {
            return 'URL';
        }
        
        // Handle patterns
        if (trimmed.match(/^\d{5}\//)) {
            return 'Handle';
        }
        
        // Default to DOI if it looks like one
        if (trimmed.includes('/') && !trimmed.includes(' ')) {
            return 'DOI';
        }
        
        return 'URL';
    };

    const detectedType = detectIdentifierType(identifier);

    // Validate identifier with API
    const validation = useIdentifierValidation({
        identifier,
        identifierType: detectedType,
        enabled: identifier.trim().length > 0,
        debounceMs: 800,
    });

    const handleAdd = () => {
        if (!identifier.trim() || validation.status === 'invalid') {
            return;
        }

        const identifierType = detectIdentifierType(identifier);
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

        // Reset form
        setIdentifier('');
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
        setRelationType(value as RelationType);
        const opposite = getOppositeRelationType(value as RelationType);
        setShowSuggestion(!!opposite);
    };

    const oppositeRelation = getOppositeRelationType(relationType);

    // Quick suggestion for opposite relation
    const handleUseSuggestion = () => {
        if (oppositeRelation) {
            setRelationType(oppositeRelation);
            setShowSuggestion(false);
        }
    };

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="space-y-2">
                <Label className="text-base font-semibold">
                    Related Work
                </Label>
                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Info className="h-4 w-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
                    <p>
                        Add relationships to other datasets, publications, or resources.
                        Enter a DOI, URL, or other identifier and select the relationship type.
                    </p>
                </div>
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
                            type="text"
                            value={identifier}
                            onChange={(e) => {
                                setIdentifier(e.target.value);
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
                            <div className="absolute right-3 top-1/2 -translate-y-1/2">
                                <div className="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                            </div>
                        )}
                    </div>
                    {validation.status === 'invalid' && (
                        <p className="mt-1 text-xs text-red-600">
                            Invalid {detectedType} format
                        </p>
                    )}
                    {validation.status === 'warning' && (
                        <p className="mt-1 text-xs text-yellow-600">
                            <Info className="mr-1 inline h-3 w-3" />
                            Could not verify via API, but format is valid
                        </p>
                    )}
                    {validation.status === 'valid' && validation.metadata?.title && (
                        <p className="mt-1 text-xs text-green-600">
                            ✓ {validation.metadata.title}
                        </p>
                    )}
                    {validation.status === 'idle' && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Type will be auto-detected (DOI, URL, Handle)
                        </p>
                    )}
                </div>

                {/* Relation Type Select */}
                <div className="md:col-span-5">
                    <Label htmlFor="relation-type" className="sr-only">
                        Relation Type
                    </Label>
                    <Select
                        value={relationType}
                        onValueChange={handleRelationTypeChange}
                    >
                        <SelectTrigger id="relation-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground">
                                Most Used
                            </div>
                            {MOST_USED_RELATION_TYPES.map((type) => (
                                <SelectItem key={type} value={type}>
                                    <div className="flex flex-col items-start">
                                        <span>{type}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {RELATION_TYPE_DESCRIPTIONS[type].substring(0, 50)}...
                                        </span>
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
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            {/* Bidirectional Suggestion */}
            {showSuggestion && oppositeRelation && (
                <Alert className="bg-blue-50 border-blue-200">
                    <Lightbulb className="h-4 w-4 text-blue-600" />
                    <AlertDescription className="flex items-center justify-between">
                        <span className="text-sm">
                            Did you mean <strong>{oppositeRelation}</strong> instead?
                            <span className="text-muted-foreground ml-1">
                                ({RELATION_TYPE_DESCRIPTIONS[oppositeRelation]})
                            </span>
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={handleUseSuggestion}
                            className="ml-4 flex-shrink-0"
                        >
                            Use {oppositeRelation}
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {/* Advanced Mode Toggle */}
            {onToggleAdvanced && (
                <div className="pt-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onToggleAdvanced}
                        className="text-xs"
                    >
                        {showAdvancedMode ? '← Simple mode' : 'Show all relation types →'}
                    </Button>
                </div>
            )}
        </div>
    );
}
