import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

type PlaceholderData = {
    totalPlaceholders: number;
    datasetsWithPlaceholders: number;
    patterns: Array<{
        pattern: string;
        count: number;
    }>;
};

type QualityData = {
    completeData: number;
    incompleteOrPlaceholder: number;
    percentageComplete: number;
};

type DataQualityIndicatorsProps = {
    placeholders: PlaceholderData;
    quality: QualityData;
};

export default function DataQualityIndicators({
    placeholders,
    quality,
}: DataQualityIndicatorsProps) {
    const hasPlaceholders = placeholders.totalPlaceholders > 0;
    const isHighQuality = quality.percentageComplete >= 99;

    return (
        <div className="space-y-4">
            {/* Overall Quality Alert */}
            {isHighQuality ? (
                <Alert className="border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950">
                    <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                    <AlertTitle className="text-emerald-900 dark:text-emerald-100">
                        Excellent Data Quality
                    </AlertTitle>
                    <AlertDescription className="text-emerald-800 dark:text-emerald-200">
                        {quality.percentageComplete}% of related work entries have complete,
                        valid data.
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                    <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                    <AlertTitle className="text-amber-900 dark:text-amber-100">
                        Data Quality Needs Attention
                    </AlertTitle>
                    <AlertDescription className="text-amber-800 dark:text-amber-200">
                        {quality.percentageComplete}% complete. {quality.incompleteOrPlaceholder}{' '}
                        entries contain placeholder values.
                    </AlertDescription>
                </Alert>
            )}

            {/* Quality Metrics Cards */}
            <div className="grid gap-4 md:grid-cols-3">
                <div className="rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2">
                        <CheckCircle2 className="h-5 w-5 text-emerald-500" />
                        <h4 className="text-sm font-medium">Complete Data</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{quality.completeData.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">Valid related work entries</p>
                </div>

                <div className="rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-amber-500" />
                        <h4 className="text-sm font-medium">Placeholders</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">
                        {quality.incompleteOrPlaceholder.toLocaleString()}
                    </p>
                    <p className="text-sm text-muted-foreground">Entries with placeholder values</p>
                </div>

                <div className="rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2">
                        <Info className="h-5 w-5 text-blue-500" />
                        <h4 className="text-sm font-medium">Completion Rate</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{quality.percentageComplete}%</p>
                    <p className="text-sm text-muted-foreground">Overall data quality</p>
                </div>
            </div>

            {/* Placeholder Details */}
            {hasPlaceholders && placeholders.patterns.length > 0 && (
                <div className="rounded-lg border bg-card p-4">
                    <h4 className="mb-3 flex items-center gap-2 text-sm font-medium">
                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                        Placeholder Patterns Detected
                    </h4>
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            {placeholders.datasetsWithPlaceholders} dataset
                            {placeholders.datasetsWithPlaceholders !== 1 ? 's' : ''} affected by{' '}
                            {placeholders.totalPlaceholders} placeholder entr
                            {placeholders.totalPlaceholders !== 1 ? 'ies' : 'y'}:
                        </p>
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted">
                                    <tr>
                                        <th className="p-2 text-left font-medium">Pattern</th>
                                        <th className="p-2 text-right font-medium">Occurrences</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {placeholders.patterns.map((pattern) => (
                                        <tr key={pattern.pattern} className="border-t">
                                            <td className="p-2 font-mono text-xs">
                                                "{pattern.pattern}"
                                            </td>
                                            <td className="p-2 text-right font-bold">
                                                {pattern.count}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}

            {/* No Placeholders Message */}
            {!hasPlaceholders && (
                <Alert>
                    <Info className="h-4 w-4" />
                    <AlertTitle>No Placeholder Values Found</AlertTitle>
                    <AlertDescription>
                        All related work entries contain valid, complete data.
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}
