import { Info } from 'lucide-react';
import { useState } from 'react';

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
    getAllRelationTypes,
    RELATION_TYPE_DESCRIPTIONS,
} from '@/lib/related-identifiers';
import type { IdentifierType, RelatedIdentifierFormData, RelationType } from '@/types';

interface RelatedWorkAdvancedAddProps {
    onAdd: (data: RelatedIdentifierFormData) => void;
}

/**
 * RelatedWorkAdvancedAdd Component
 * 
 * Advanced mode showing all 33 DataCite relation types
 * grouped by category for better navigation.
 */
export default function RelatedWorkAdvancedAdd({
    onAdd,
}: RelatedWorkAdvancedAddProps) {
    const [identifier, setIdentifier] = useState('');
    const [identifierType, setIdentifierType] = useState<IdentifierType>('DOI');
    const [relationType, setRelationType] = useState<RelationType>('Cites');

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

        onAdd({
            identifier: identifier.trim(),
            identifierType,
            relationType,
        });

        // Reset form
        setIdentifier('');
    };

    const handleKeyPress = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleAdd();
        }
    };

    const identifierTypes: IdentifierType[] = [
        'DOI',
        'URL',
        'Handle',
        'IGSN',
        'URN',
        'ISBN',
        'ISSN',
        'PURL',
        'ARK',
        'arXiv',
        'bibcode',
        'EAN13',
        'EISSN',
        'ISTC',
        'LISSN',
        'LSID',
        'PMID',
        'UPC',
        'w3id',
    ];

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="space-y-2">
                <Label className="text-base font-semibold">
                    Advanced Mode - All Relation Types
                </Label>
                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Info className="h-4 w-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
                    <p>
                        Browse all 33 DataCite relation types organized by category.
                        Select the precise relationship that matches your needs.
                    </p>
                </div>
            </div>

            {/* Advanced Form */}
            <div className="grid gap-4 md:grid-cols-12">
                {/* Identifier Input */}
                <div className="md:col-span-5">
                    <Label htmlFor="advanced-identifier">
                        Identifier
                    </Label>
                    <div className="relative">
                        <Input
                            id="advanced-identifier"
                            type="text"
                            value={identifier}
                            onChange={(e) => setIdentifier(e.target.value)}
                            onKeyPress={handleKeyPress}
                            placeholder="e.g., 10.5194/nhess-15-1463-2015"
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
                            Invalid {identifierType} format
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
                </div>

                {/* Identifier Type Select */}
                <div className="md:col-span-2">
                    <Label htmlFor="advanced-identifier-type">
                        Type
                    </Label>
                    <Select
                        value={identifierType}
                        onValueChange={(value) => setIdentifierType(value as IdentifierType)}
                    >
                        <SelectTrigger id="advanced-identifier-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {identifierTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Relation Type Select - Grouped */}
                <div className="md:col-span-4">
                    <Label htmlFor="advanced-relation-type">
                        Relation Type
                    </Label>
                    <Select
                        value={relationType}
                        onValueChange={(value) => setRelationType(value as RelationType)}
                    >
                        <SelectTrigger id="advanced-relation-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="max-h-[400px]">
                            {getAllRelationTypes().map((type) => (
                                <SelectItem key={type} value={type}>
                                    <div className="flex flex-col items-start">
                                        <span className="font-medium">{type}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {RELATION_TYPE_DESCRIPTIONS[type]}
                                        </span>
                                    </div>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Add Button */}
                <div className="flex items-end md:col-span-1">
                    <Button
                        type="button"
                        onClick={handleAdd}
                        disabled={!identifier.trim() || validation.status === 'invalid' || validation.status === 'validating'}
                        className="w-full"
                    >
                        Add
                    </Button>
                </div>
            </div>

            {/* Selected Relation Description */}
            {relationType && (
                <div className="rounded-lg bg-muted/50 p-3">
                    <p className="text-sm">
                        <span className="font-semibold">{relationType}:</span>{' '}
                        <span className="text-muted-foreground">
                            {RELATION_TYPE_DESCRIPTIONS[relationType]}
                        </span>
                    </p>
                </div>
            )}
        </div>
    );
}
