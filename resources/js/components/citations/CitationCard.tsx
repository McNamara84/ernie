import { Copy, Pencil, Trash2 } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatCitation } from '@/lib/citation-formatter';
import type { RelatedItem } from '@/types/related-item';

export type CitationStyle = 'apa' | 'ieee';

interface CitationCardProps {
    item: RelatedItem;
    /** Optional relation-type label (e.g. "Cites", "IsSupplementTo"). */
    relationLabel?: string;
    /** Default citation style. */
    defaultStyle?: CitationStyle;
    /** When true, edit/delete buttons are rendered. */
    editable?: boolean;
    /** Hide the inline metadata badge (used on landing pages where it is redundant). */
    hideBadge?: boolean;
    onEdit?: (item: RelatedItem) => void;
    onDelete?: (item: RelatedItem) => void;
}

/**
 * Renders a single {@link RelatedItem} as a formatted citation with a style
 * toggle (APA / IEEE) and copy-to-clipboard action.
 *
 * Used both in the Citation Manager modal and on public landing pages.
 */
export function CitationCard({
    item,
    relationLabel,
    defaultStyle = 'apa',
    editable = false,
    hideBadge = false,
    onEdit,
    onDelete,
}: CitationCardProps) {
    const [style, setStyle] = useState<CitationStyle>(defaultStyle);
    const citation = formatCitation(item, style);

    const handleCopy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(citation);
            toast.success('Citation copied to clipboard');
        } catch {
            toast.error('Could not copy citation');
        }
    }, [citation]);

    return (
        <Card data-slot="citation-card" className="border-border/60">
            <CardContent className="flex flex-col gap-3 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    {relationLabel ? (
                        <Badge variant="outline" data-slot="citation-relation">
                            {relationLabel}
                        </Badge>
                    ) : null}
                    <Badge variant="secondary">{item.related_item_type}</Badge>
                    {!hideBadge ? (
                        <Badge variant="outline" className="text-xs">
                            Inline metadata
                        </Badge>
                    ) : null}

                    <div className="ml-auto flex items-center gap-1">
                        <ToggleGroup
                            type="single"
                            size="sm"
                            value={style}
                            onValueChange={(value) => {
                                if (value === 'apa' || value === 'ieee') setStyle(value);
                            }}
                            aria-label="Citation style"
                        >
                            <ToggleGroupItem value="apa" aria-label="APA style">
                                APA
                            </ToggleGroupItem>
                            <ToggleGroupItem value="ieee" aria-label="IEEE style">
                                IEEE
                            </ToggleGroupItem>
                        </ToggleGroup>

                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={handleCopy}
                            aria-label="Copy citation"
                            title="Copy citation"
                        >
                            <Copy className="h-4 w-4" />
                        </Button>

                        {editable && onEdit ? (
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => onEdit(item)}
                                aria-label="Edit related item"
                                title="Edit related item"
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                        ) : null}
                        {editable && onDelete ? (
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => onDelete(item)}
                                aria-label="Delete related item"
                                title="Delete related item"
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        ) : null}
                    </div>
                </div>

                <p data-slot="citation-text" className="text-sm leading-relaxed">
                    {citation}
                </p>

                {item.identifier ? (
                    <div className="text-xs text-muted-foreground">
                        {item.identifier_type === 'DOI' ? (
                            <a
                                href={`https://doi.org/${encodeURI(item.identifier)}`}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="underline decoration-dotted underline-offset-2 hover:text-foreground"
                            >
                                https://doi.org/{item.identifier}
                            </a>
                        ) : (
                            <span>
                                {item.identifier_type}: {item.identifier}
                            </span>
                        )}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
