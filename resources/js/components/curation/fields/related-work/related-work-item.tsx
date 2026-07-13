import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { AlertCircle, CheckCircle2, ExternalLink, GripVertical, Trash2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { isRepositoryCurationRelatedIdentifier } from '@/lib/related-identifier-provenance';
import { formatRelationTypeLabel, getAllRelationTypes, MOST_USED_RELATION_TYPES, RELATION_TYPE_DESCRIPTIONS } from '@/lib/related-identifiers';
import { cn } from '@/lib/utils';
import { resolveIdentifierUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';
import { identifierTypes } from '@/schemas/related-work.schema';
import type { RelatedIdentifier, RelationType } from '@/types';

interface RelatedWorkItemProps {
    sortableId: string;
    item: RelatedIdentifier;
    index: number;
    onChange: (item: RelatedIdentifier) => void;
    onRemove: (index: number) => void;
    activeRelationTypes?: string[];
    activeIdentifierTypes?: string[];
    validationStatus?: 'validating' | 'valid' | 'invalid' | 'warning';
    validationMessage?: string;
}

/**
 * RelatedWorkItem Component
 *
 * Displays a single related work item with:
 * - Editable identifier, type, relation type, and citation label fields
 * - External link preview for DOI, URL, and Handle identifiers
 * - Drag handle for ordering
 * - Validation status indicator
 * - Remove button
 */
export default function RelatedWorkItem({
    sortableId,
    item,
    index,
    onChange,
    onRemove,
    activeRelationTypes,
    activeIdentifierTypes,
    validationStatus,
    validationMessage,
}: RelatedWorkItemProps) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: sortableId });

    const filteredRelationTypes = activeRelationTypes
        ? getAllRelationTypes().filter((type) => activeRelationTypes.includes(type))
        : getAllRelationTypes();
    const filteredMostUsedRelationTypes = MOST_USED_RELATION_TYPES.filter((type) => filteredRelationTypes.includes(type));
    const additionalRelationTypes = filteredRelationTypes.filter((type) => !filteredMostUsedRelationTypes.includes(type));
    const filteredIdentifierTypes = activeIdentifierTypes
        ? identifierTypes.filter((type) => activeIdentifierTypes.includes(type))
        : [...identifierTypes];

    const previewLinkIdentifierTypes = new Set(['DOI', 'URL', 'Handle']);
    const linkUrl = previewLinkIdentifierTypes.has(item.identifier_type) ? resolveIdentifierUrl(item.identifier, item.identifier_type) : null;
    const isClickable = linkUrl !== null;
    const isRepositoryCuration = isRepositoryCurationRelatedIdentifier(item);
    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    // Get relation type description
    const description = RELATION_TYPE_DESCRIPTIONS[item.relation_type as RelationType] || '';
    const updateItem = (patch: Partial<RelatedIdentifier>) => {
        onChange({
            ...item,
            ...patch,
        });
    };

    // Validation icon
    const ValidationIcon = () => {
        if (!validationStatus || validationStatus === 'validating') return null;

        if (validationStatus === 'valid') {
            return (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <CheckCircle2 className="h-4 w-4 text-green-600" aria-label="Valid" />
                    </TooltipTrigger>
                    <TooltipContent>
                        <p className="text-sm">Identifier validated successfully</p>
                    </TooltipContent>
                </Tooltip>
            );
        }

        if (validationStatus === 'warning') {
            return (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <AlertCircle className="h-4 w-4 text-yellow-600" aria-label="Warning" />
                    </TooltipTrigger>
                    <TooltipContent>
                        <p className="text-sm">{validationMessage || 'Validation warning'}</p>
                    </TooltipContent>
                </Tooltip>
            );
        }

        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <AlertCircle className="h-4 w-4 text-red-600" aria-label="Invalid" />
                </TooltipTrigger>
                <TooltipContent>
                    <p className="text-sm">{validationMessage || 'Validation failed'}</p>
                </TooltipContent>
            </Tooltip>
        );
    };

    return (
        <div ref={setNodeRef} style={style} role="listitem">
            <Card
                className={cn(
                    'p-4',
                    isRepositoryCuration && 'border-cyan-200 bg-cyan-50/70 dark:border-cyan-800 dark:bg-cyan-950/20',
                    isDragging && 'border-primary/50 shadow-lg',
                )}
            >
                {isRepositoryCuration && (
                    <div className="mb-3 rounded-md border border-cyan-200 bg-cyan-100/70 px-3 py-2 text-xs font-medium text-cyan-900 dark:border-cyan-800 dark:bg-cyan-950/40 dark:text-cyan-100">
                        Related identifier added via the assistance tool.
                    </div>
                )}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="mt-0.5 h-8 w-8 shrink-0 cursor-grab text-muted-foreground"
                            aria-label={`Reorder related work ${index + 1}`}
                            {...attributes}
                            {...listeners}
                        >
                            <GripVertical className="h-4 w-4" />
                        </Button>

                        <div className="space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <h4 className="text-sm font-semibold text-foreground">Related Work {index + 1}</h4>
                                <Badge variant="outline" className="text-xs">
                                    {formatRelationTypeLabel(item.relation_type)}
                                </Badge>
                                <Badge variant="secondary" className="text-xs" data-testid="identifier-type-badge">
                                    {item.identifier_type}
                                </Badge>
                                <ValidationIcon />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Edit the identifier, relation type, and landing-page citation label in one place.
                            </p>
                        </div>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => onRemove(index)}
                        aria-label="Remove related work"
                        className="h-8 w-8 shrink-0"
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                </div>

                <div className="mt-4 grid gap-4 md:grid-cols-12">
                    <div className="md:col-span-5">
                        <Label htmlFor={`related-work-${index}-identifier`}>Identifier</Label>
                        <Input
                            id={`related-work-${index}-identifier`}
                            type="text"
                            value={item.identifier}
                            onChange={(event) => updateItem({ identifier: event.target.value })}
                            placeholder="e.g., 10.5194/nhess-15-1463-2015"
                            className="font-mono text-sm"
                        />
                    </div>

                    <div className="md:col-span-2">
                        <Label htmlFor={`related-work-${index}-identifier-type`}>Type</Label>
                        <Select value={item.identifier_type} onValueChange={(value) => updateItem({ identifier_type: value })}>
                            <SelectTrigger id={`related-work-${index}-identifier-type`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {filteredIdentifierTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {type}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="md:col-span-5">
                        <Label htmlFor={`related-work-${index}-relation-type`}>Relation type</Label>
                        <Select
                            value={item.relation_type}
                            onValueChange={(value) =>
                                updateItem({
                                    relation_type: value,
                                    relation_type_information: value === 'Other' ? (item.relation_type_information ?? '') : null,
                                })
                            }
                        >
                            <SelectTrigger id={`related-work-${index}-relation-type`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="max-h-100">
                                {filteredMostUsedRelationTypes.length > 0 && (
                                    <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground">Most Used</div>
                                )}
                                {filteredMostUsedRelationTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        <div className="flex flex-col items-start">
                                            <span className="font-medium">{formatRelationTypeLabel(type)}</span>
                                            <span className="text-xs text-muted-foreground">{RELATION_TYPE_DESCRIPTIONS[type]}</span>
                                        </div>
                                    </SelectItem>
                                ))}
                                {additionalRelationTypes.length > 0 && (
                                    <div className="px-2 pt-3 pb-1.5 text-xs font-semibold text-muted-foreground">All relation types</div>
                                )}
                                {additionalRelationTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        <div className="flex flex-col items-start">
                                            <span className="font-medium">{formatRelationTypeLabel(type)}</span>
                                            <span className="text-xs text-muted-foreground">{RELATION_TYPE_DESCRIPTIONS[type]}</span>
                                        </div>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="mt-4 space-y-2">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="font-medium text-foreground">Preview</span>
                        {isClickable ? (
                            <a
                                href={linkUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="group inline-flex min-w-0 items-center gap-1 text-primary hover:underline"
                            >
                                <span className="break-all">{item.identifier}</span>
                                <ExternalLink className="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
                            </a>
                        ) : (
                            <span className="break-all text-muted-foreground">{item.identifier}</span>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">{description}</p>
                </div>

                <div className="mt-4">
                    <div>
                        <Label htmlFor={`related-work-${index}-citation-label`}>Citation label</Label>
                        <Textarea
                            id={`related-work-${index}-citation-label`}
                            value={item.citation_label ?? ''}
                            onChange={(event) => updateItem({ citation_label: event.target.value })}
                            rows={3}
                            placeholder="Optional formatted citation shown on landing pages"
                        />
                        <p className="mt-1 text-xs text-muted-foreground">
                            This text is preferred on landing pages and in relation graphs. Leave it empty to let the backend resolve a DOI citation.
                        </p>
                    </div>
                </div>

                {item.relation_type === 'Other' && (
                    <div className="mt-4 space-y-1">
                        <Label htmlFor={`related-work-${index}-relation-type-information`}>Relation type information</Label>
                        <Input
                            id={`related-work-${index}-relation-type-information`}
                            type="text"
                            value={item.relation_type_information ?? ''}
                            onChange={(event) => updateItem({ relation_type_information: event.target.value })}
                            placeholder="Describe the custom relationship"
                        />
                    </div>
                )}
            </Card>
        </div>
    );
}
