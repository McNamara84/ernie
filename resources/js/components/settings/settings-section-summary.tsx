import { Badge } from '@/components/ui/badge';

interface SettingsSectionSummaryProps {
    items: string[];
}

export function pluralizedCount(count: number, singular: string, plural = `${singular}s`): string {
    return `${count} ${count === 1 ? singular : plural}`;
}

export function SettingsSectionSummary({ items }: SettingsSectionSummaryProps) {
    const accessibleSummary = items.join(', ');

    return (
        <span className="flex flex-wrap gap-1.5" aria-label={accessibleSummary}>
            {items.map((item, index) => (
                <Badge key={index} variant="secondary" className="font-normal" aria-hidden="true">
                    {item}
                </Badge>
            ))}
        </span>
    );
}
