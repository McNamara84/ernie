import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { RELATION_TYPE_DESCRIPTIONS } from '@/lib/related-identifiers';
import type { AssistanceRelationTypeOption, SuggestedRelationItem } from '@/types/assistance';

interface RelationTypeSelectProps {
    suggestion: SuggestedRelationItem;
    options: AssistanceRelationTypeOption[];
    value: number;
    disabled: boolean;
    onValueChange: (relationTypeId: number) => void;
}

function optionDescription(slug: string): string | null {
    return (RELATION_TYPE_DESCRIPTIONS as Record<string, string>)[slug] ?? null;
}

function RelationTypeOptionLabel({ option, inactive = false }: { option: AssistanceRelationTypeOption; inactive?: boolean }) {
    const description = optionDescription(option.slug);

    return (
        <div className="flex min-w-0 flex-col items-start">
            <span>{inactive ? `${option.name} (inactive)` : option.name}</span>
            {description && <span className="max-w-96 truncate text-xs text-muted-foreground">{description}</span>}
        </div>
    );
}

export function RelationTypeSelect({ suggestion, options, value, disabled, onValueChange }: RelationTypeSelectProps) {
    const selectId = `relation-type-${suggestion.id}`;
    const originalOption = options.find((option) => option.id === suggestion.relation_type_id);
    const inactiveOriginal: AssistanceRelationTypeOption | null = originalOption
        ? null
        : {
              id: suggestion.relation_type_id,
              name: suggestion.relation_type_name || suggestion.relation_type,
              slug: suggestion.relation_type,
              usage_count: 0,
              is_most_used: false,
          };
    const mostUsed = options.filter((option) => option.is_most_used);
    const remaining = options.filter((option) => !option.is_most_used);

    return (
        <div className="min-w-56 space-y-1">
            <Label htmlFor={selectId} className="text-xs text-muted-foreground">
                Relation Type
            </Label>
            <Select value={String(value)} disabled={disabled} onValueChange={(nextValue) => onValueChange(Number(nextValue))}>
                <SelectTrigger
                    id={selectId}
                    size="sm"
                    className="w-full"
                    aria-label={`Relation type for ${suggestion.identifier}`}
                    data-testid={`relation-type-select-${suggestion.id}`}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent className="max-w-lg">
                    {inactiveOriginal && (
                        <SelectGroup>
                            <SelectLabel className="font-semibold">Suggested Type</SelectLabel>
                            <SelectItem value={String(inactiveOriginal.id)}>
                                <RelationTypeOptionLabel option={inactiveOriginal} inactive />
                            </SelectItem>
                        </SelectGroup>
                    )}
                    {mostUsed.length > 0 && (
                        <SelectGroup>
                            <SelectLabel className="font-semibold">Most Used</SelectLabel>
                            {mostUsed.map((option) => (
                                <SelectItem key={option.id} value={String(option.id)}>
                                    <RelationTypeOptionLabel option={option} />
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    )}
                    {remaining.length > 0 && (
                        <SelectGroup>
                            <SelectLabel className="font-semibold">All Relation Types</SelectLabel>
                            {remaining.map((option) => (
                                <SelectItem key={option.id} value={String(option.id)}>
                                    <RelationTypeOptionLabel option={option} />
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    )}
                </SelectContent>
            </Select>
        </div>
    );
}
