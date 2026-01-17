import { AlertCircle, CheckCircle2, ExternalLink, Trash2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { RELATION_TYPE_DESCRIPTIONS } from '@/lib/related-identifiers';
import type { RelatedIdentifier, RelationType } from '@/types';

interface RelatedWorkItemProps {
    item: RelatedIdentifier;
    index: number;
    onRemove: (index: number) => void;
    validationStatus?: 'validating' | 'valid' | 'invalid' | 'warning';
    validationMessage?: string;
}

/**
 * RelatedWorkItem Component
 *
 * Displays a single related work item with:
 * - Identifier with external link (if URL or DOI)
 * - Relation type with description tooltip
 * - Identifier type badge
 * - Validation status indicator
 * - Remove button
 */
export default function RelatedWorkItem({ item, index, onRemove, validationStatus, validationMessage }: RelatedWorkItemProps) {
    // Determine if identifier is a clickable link
    const isClickable = item.identifier_type === 'DOI' || item.identifier_type === 'URL';
    const linkUrl = item.identifier_type === 'DOI' ? `https://doi.org/${item.identifier}` : item.identifier;

    // Get relation type description
    const description = RELATION_TYPE_DESCRIPTIONS[item.relation_type as RelationType] || '';

    // Validation icon
    const ValidationIcon = () => {
        if (!validationStatus || validationStatus === 'validating') return null;

        if (validationStatus === 'valid') {
            return (
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <CheckCircle2 className="h-4 w-4 text-green-600" aria-label="Valid" />
                        </TooltipTrigger>
                        <TooltipContent>
                            <p className="text-sm">Identifier validated successfully</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            );
        }

        if (validationStatus === 'warning') {
            return (
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <AlertCircle className="h-4 w-4 text-yellow-600" aria-label="Warning" />
                        </TooltipTrigger>
                        <TooltipContent>
                            <p className="text-sm">{validationMessage || 'Validation warning'}</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            );
        }

        return (
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <AlertCircle className="h-4 w-4 text-red-600" aria-label="Invalid" />
                    </TooltipTrigger>
                    <TooltipContent>
                        <p className="text-sm">{validationMessage || 'Validation failed'}</p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        );
    };

    return (
        <Card className="p-4">
            <div className="flex items-start justify-between gap-4">
                <div className="flex-1 space-y-2">
                    {/* Relation Type with Tooltip */}
                    <div className="flex items-center gap-2">
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="text-sm font-semibold text-foreground">{item.relation_type}</span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p className="max-w-xs text-sm">{description}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <Badge variant="secondary" className="text-xs" data-testid="identifier-type-badge">
                            {item.identifier_type}
                        </Badge>
                    </div>

                    {/* Identifier with optional link */}
                    <div className="flex items-center gap-2">
                        {isClickable ? (
                            <a
                                href={linkUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="group flex items-center gap-1 text-sm text-primary hover:underline"
                            >
                                <span className="break-all">{item.identifier}</span>
                                <ExternalLink className="h-3 w-3 flex-shrink-0 opacity-0 transition-opacity group-hover:opacity-100" />
                            </a>
                        ) : (
                            <span className="text-sm break-all text-muted-foreground">{item.identifier}</span>
                        )}
                        <ValidationIcon />
                    </div>

                    {/* Optional: Related title from API */}
                    {item.related_title && <p className="text-xs text-muted-foreground italic">{item.related_title}</p>}
                </div>

                {/* Remove Button */}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => onRemove(index)}
                    aria-label="Remove related work"
                    className="h-8 w-8 flex-shrink-0"
                >
                    <Trash2 className="h-4 w-4" />
                </Button>
            </div>
        </Card>
    );
}
