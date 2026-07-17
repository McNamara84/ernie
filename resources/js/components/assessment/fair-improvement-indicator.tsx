import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type FairImprovementOpportunity, type FairImprovementSeverity } from '@/types/assessment';

const severityClasses: Record<FairImprovementSeverity, string> = {
    low: 'border-yellow-400 bg-yellow-100 text-yellow-800 hover:bg-yellow-200 dark:border-yellow-500 dark:bg-yellow-950/60 dark:text-yellow-300 dark:hover:bg-yellow-950',
    medium: 'border-amber-500 bg-amber-100 text-amber-800 hover:bg-amber-200 dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-300 dark:hover:bg-amber-950',
    high: 'border-orange-500 bg-orange-100 text-orange-800 hover:bg-orange-200 dark:border-orange-500 dark:bg-orange-950/60 dark:text-orange-300 dark:hover:bg-orange-950',
    'very-high':
        'border-red-500 bg-red-100 text-red-800 hover:bg-red-200 dark:border-red-500 dark:bg-red-950/60 dark:text-red-300 dark:hover:bg-red-950',
};

const severityLabels: Record<FairImprovementSeverity, string> = {
    low: 'low',
    medium: 'medium',
    high: 'high',
    'very-high': 'very high',
};

const actorLabels = {
    curator: 'Curator action',
    administrator: 'ERNIE administrator action',
} as const;

const triggerClasses = 'border text-sm font-bold';

function formatPoints(value: number): string {
    return Number.isInteger(value) ? value.toString() : value.toFixed(2);
}

function formatGain(value: number): string {
    return value.toFixed(2);
}

function availablePointsCopy(missingPoints: number, totalPoints: number): string {
    if (missingPoints === 1) {
        return `1 F-UJI point out of ${formatPoints(totalPoints)} is available`;
    }

    return `${formatPoints(missingPoints)} of ${formatPoints(totalPoints)} F-UJI points are available`;
}

function availableLabel(opportunity: Extract<FairImprovementOpportunity, { status: 'available' }>): string {
    return `${opportunity.dimensionLabel}: ${severityLabels[opportunity.severity]} FAIR improvement potential; ${availablePointsCopy(opportunity.missingPoints, opportunity.totalPoints)}, worth up to ${formatGain(opportunity.potentialFairGain)} overall percentage points.`;
}

function AvailableTooltip({ opportunity }: { opportunity: Extract<FairImprovementOpportunity, { status: 'available' }> }) {
    const showSuggestions = opportunity.guidanceMessage === undefined && opportunity.suggestions.length > 0;

    return (
        <div className="space-y-2">
            <div>
                <p className="font-semibold">{opportunity.dimensionLabel} offers the largest FAIR-score opportunity.</p>
                <p className="mt-1">
                    {availablePointsCopy(opportunity.missingPoints, opportunity.totalPoints)} (up to +{formatGain(opportunity.potentialFairGain)}{' '}
                    percentage points overall).
                </p>
            </div>

            {showSuggestions ? (
                <div>
                    <p className="font-semibold">Increase the FAIR score by:</p>
                    <ol className="mt-1 list-decimal space-y-1 pl-4">
                        {opportunity.suggestions.slice(0, 3).map((suggestion) => (
                            <li key={suggestion.key}>
                                <span>{suggestion.text}</span> <span className="font-semibold">({actorLabels[suggestion.actor]})</span>
                            </li>
                        ))}
                    </ol>
                </div>
            ) : (
                opportunity.guidanceMessage !== undefined && <p>{opportunity.guidanceMessage}</p>
            )}

            {opportunity.scopeNote !== undefined && <p className="border-t border-background/30 pt-2">{opportunity.scopeNote}</p>}
        </div>
    );
}

export function FairImprovementIndicator({ opportunity }: { opportunity: FairImprovementOpportunity }) {
    if (opportunity.status !== 'available') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        className={`${triggerClasses} border-transparent text-muted-foreground hover:border-border hover:bg-muted`}
                        aria-label={opportunity.message}
                    >
                        <span aria-hidden="true">—</span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top" className="max-w-sm text-left whitespace-normal">
                    {opportunity.message}
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    className={`${triggerClasses} ${severityClasses[opportunity.severity]}`}
                    aria-label={availableLabel(opportunity)}
                >
                    <span aria-hidden="true">{opportunity.dimension}</span>
                </Button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-sm text-left whitespace-normal">
                <AvailableTooltip opportunity={opportunity} />
            </TooltipContent>
        </Tooltip>
    );
}
