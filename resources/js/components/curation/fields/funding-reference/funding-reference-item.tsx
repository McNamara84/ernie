import { ChevronDown, ChevronRight, GripVertical, Trash2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

import InputField from '../input-field';
import type { FundingReferenceEntry } from './types';

interface FundingReferenceItemProps {
    funding: FundingReferenceEntry;
    index: number;
    onFunderNameChange: (value: string) => void;
    onAwardNumberChange: (value: string) => void;
    onAwardUriChange: (value: string) => void;
    onAwardTitleChange: (value: string) => void;
    onToggleExpanded: () => void;
    onRemove: () => void;
    canRemove: boolean;
    // dragHandleProps will be added when implementing @dnd-kit
}

export function FundingReferenceItem({
    funding,
    index,
    onFunderNameChange,
    onAwardNumberChange,
    onAwardUriChange,
    onAwardTitleChange,
    onToggleExpanded,
    onRemove,
    canRemove,
}: FundingReferenceItemProps) {
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

            {/* Funder Name (Required) */}
            <div className="mt-6 space-y-4">
                <InputField
                    id={`${funding.id}-funder-name`}
                    label="Funder Name"
                    value={funding.funderName}
                    onChange={(e) => onFunderNameChange(e.target.value)}
                    placeholder="e.g., Deutsche Forschungsgemeinschaft (DFG)"
                    required
                />

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
