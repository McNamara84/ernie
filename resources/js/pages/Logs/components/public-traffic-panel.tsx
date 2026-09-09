import axios from 'axios';
import { Clock3, RefreshCw, TrendingDown, TrendingUp, Users } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type PublicTrafficPeriod = '4w' | '12w' | '52w';
type PublicTrafficStatus = 'disabled' | 'collecting' | 'available';

export interface PublicTrafficCell {
    weekday: number;
    hour: number;
    combinedAverage: number | null;
    landingPageAverage: number | null;
    portalAverage: number | null;
    sampleCount: number;
}

export interface PublicTrafficResponse {
    period: PublicTrafficPeriod;
    periodWeeks: number;
    timezone: string;
    requestedFrom: string;
    effectiveFrom: string | null;
    to: string;
    status: PublicTrafficStatus;
    collectionStartedAt: string | null;
    lastCompleteBucketAt: string | null;
    coverage: {
        completeHours: number;
        excludedHours: number;
        minimumCellSampleCount: number;
        lowSampleWarning: boolean;
    };
    cells: PublicTrafficCell[];
    quietest: PublicTrafficCell[];
    busiest: PublicTrafficCell[];
}

interface PublicTrafficPanelProps {
    refreshKey?: number;
}

const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

function formatHour(hour: number): string {
    return `${hour.toString().padStart(2, '0')}:00`;
}

function formatWindow(cell: PublicTrafficCell): string {
    return `${weekdays[cell.weekday - 1]}, ${formatHour(cell.hour)}–${formatHour((cell.hour + 1) % 24)}`;
}

function formatAverage(value: number | null): string {
    if (value === null) {
        return 'No data';
    }

    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(value);
}

function formatDate(value: string, timezone: string): string {
    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeZone: timezone,
    }).format(new Date(value));
}

function cellLabel(cell: PublicTrafficCell): string {
    if (cell.combinedAverage === null) {
        return `${formatWindow(cell)}: no complete observations`;
    }

    return `${formatWindow(cell)}: ${formatAverage(cell.combinedAverage)} estimated visitors on average; ${formatAverage(cell.landingPageAverage)} landing pages; ${formatAverage(cell.portalAverage)} portal; ${cell.sampleCount} observations`;
}

function intensityClass(cell: PublicTrafficCell, maximum: number): string {
    if (cell.combinedAverage === null) {
        return 'bg-muted/60 text-muted-foreground hover:bg-muted/80';
    }

    if (cell.combinedAverage === 0 || maximum === 0) {
        return 'bg-emerald-50 text-emerald-950 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-100 dark:hover:bg-emerald-950/60';
    }

    const ratio = cell.combinedAverage / maximum;
    if (ratio <= 0.2) return 'bg-sky-100 text-sky-950 hover:bg-sky-200 dark:bg-sky-950/50 dark:text-sky-100 dark:hover:bg-sky-950/70';
    if (ratio <= 0.4) return 'bg-sky-200 text-sky-950 hover:bg-sky-300 dark:bg-sky-900/60 dark:text-sky-50 dark:hover:bg-sky-900/80';
    if (ratio <= 0.6) return 'bg-blue-300 text-blue-950 hover:bg-blue-400 dark:bg-blue-800 dark:text-blue-50 dark:hover:bg-blue-700';
    if (ratio <= 0.8) return 'bg-blue-600 text-white hover:bg-blue-700 dark:bg-blue-700 dark:hover:bg-blue-600';

    return 'bg-blue-700 text-white hover:bg-blue-800 dark:bg-blue-500 dark:text-blue-950 dark:hover:bg-blue-400';
}

function RankingCard({
    title,
    description,
    cells,
    icon: Icon,
}: {
    title: string;
    description: string;
    cells: PublicTrafficCell[];
    icon: typeof TrendingDown;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="pb-3">
                <div className="flex items-start gap-2">
                    <Icon aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                    <div>
                        <CardTitle className="text-base">{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <ol className="space-y-3">
                    {cells.map((cell, index) => (
                        <li key={`${cell.weekday}-${cell.hour}`} className="flex items-start justify-between gap-4 rounded-md border p-3">
                            <div>
                                <p className="font-medium">
                                    {index + 1}. {formatWindow(cell)}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Landing pages {formatAverage(cell.landingPageAverage)} · Portal {formatAverage(cell.portalAverage)}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="text-lg font-semibold tabular-nums">{formatAverage(cell.combinedAverage)}</p>
                                <p className="text-xs text-muted-foreground">visitors/hour</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </CardContent>
        </Card>
    );
}

function TrafficHeatmap({ cells }: { cells: PublicTrafficCell[] }) {
    const maximum = Math.max(0, ...cells.map((cell) => cell.combinedAverage ?? 0));

    return (
        <Card className="min-w-0 overflow-hidden">
            <CardHeader>
                <CardTitle>Typical weekly traffic</CardTitle>
                <CardDescription>Average estimated signed-out visitors per complete observed hour.</CardDescription>
            </CardHeader>
            <CardContent className="min-w-0">
                <TooltipProvider>
                    <div className="overflow-x-auto pb-2">
                        <table className="min-w-[64rem] border-separate border-spacing-1" aria-label="Public traffic heatmap by weekday and hour">
                            <thead>
                                <tr>
                                    <th scope="col" className="sticky left-0 z-10 bg-card px-2 text-left text-xs text-muted-foreground">
                                        Day
                                    </th>
                                    {Array.from({ length: 24 }, (_, hour) => (
                                        <th key={hour} scope="col" className="px-0 text-center text-[10px] font-medium text-muted-foreground">
                                            {hour.toString().padStart(2, '0')}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {weekdays.map((weekday, dayIndex) => (
                                    <tr key={weekday}>
                                        <th scope="row" className="sticky left-0 z-10 bg-card px-2 text-left text-xs font-medium">
                                            {weekday}
                                        </th>
                                        {cells.slice(dayIndex * 24, dayIndex * 24 + 24).map((cell) => (
                                            <td key={cell.hour} className="p-0.5 text-center">
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={cellLabel(cell)}
                                                            className={cn(
                                                                'rounded-sm text-[10px] font-medium tabular-nums ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                                                                intensityClass(cell, maximum),
                                                            )}
                                                        >
                                                            {cell.combinedAverage === null ? '—' : formatAverage(cell.combinedAverage)}
                                                        </Button>
                                                    </TooltipTrigger>
                                                    <TooltipContent className="max-w-72" side="top">
                                                        <p className="font-medium">{formatWindow(cell)}</p>
                                                        {cell.combinedAverage === null ? (
                                                            <p>No complete observation is available.</p>
                                                        ) : (
                                                            <div className="mt-1 space-y-0.5">
                                                                <p>Combined: {formatAverage(cell.combinedAverage)}</p>
                                                                <p>Landing pages: {formatAverage(cell.landingPageAverage)}</p>
                                                                <p>Portal: {formatAverage(cell.portalAverage)}</p>
                                                                <p>Observed hours: {cell.sampleCount}</p>
                                                            </div>
                                                        )}
                                                    </TooltipContent>
                                                </Tooltip>
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </TooltipProvider>
                <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Traffic intensity legend">
                    <span>Quieter</span>
                    {[
                        'bg-emerald-50 dark:bg-emerald-950/40',
                        'bg-sky-200 dark:bg-sky-900/60',
                        'bg-blue-300 dark:bg-blue-800',
                        'bg-blue-600 dark:bg-blue-700',
                        'bg-blue-700 dark:bg-blue-500',
                    ].map((className) => (
                        <span key={className} aria-hidden="true" className={cn('size-5 rounded-sm border', className)} />
                    ))}
                    <span>Busier</span>
                    <span className="ml-2 inline-flex items-center gap-1">
                        <span aria-hidden="true" className="size-5 rounded-sm border bg-muted/60" /> No data
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function TrafficSkeleton() {
    return (
        <div aria-label="Loading public traffic" className="space-y-4">
            <div className="grid gap-4 lg:grid-cols-2">
                <Skeleton className="h-52 w-full" />
                <Skeleton className="h-52 w-full" />
            </div>
            <Skeleton className="h-72 w-full" />
        </div>
    );
}

export default function PublicTrafficPanel({ refreshKey = 0 }: PublicTrafficPanelProps) {
    const [period, setPeriod] = useState<PublicTrafficPeriod>('12w');
    const [traffic, setTraffic] = useState<PublicTrafficResponse | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [retryKey, setRetryKey] = useState(0);

    const loadTraffic = useCallback(
        async (signal: AbortSignal): Promise<void> => {
            setIsLoading(true);
            setError(null);

            try {
                const response = await axios.get<PublicTrafficResponse>('/logs/public-traffic', {
                    params: { period },
                    signal,
                });

                if (!signal.aborted) {
                    setTraffic(response.data);
                }
            } catch {
                if (!signal.aborted) {
                    setError('Public traffic could not be loaded.');
                }
            } finally {
                if (!signal.aborted) {
                    setIsLoading(false);
                }
            }
        },
        [period],
    );

    useEffect(() => {
        const controller = new AbortController();
        void loadTraffic(controller.signal);

        return () => controller.abort();
    }, [loadTraffic, refreshKey, retryKey]);

    return (
        <section className="min-w-0 space-y-4" aria-labelledby="public-traffic-title">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <Users aria-hidden="true" className="size-6" />
                        <h2 id="public-traffic-title" className="text-xl font-semibold">
                            Public traffic by weekday and hour
                        </h2>
                        {traffic && (
                            <Badge variant={traffic.status === 'available' ? 'default' : 'secondary'}>
                                {traffic.status === 'available' ? 'Available' : traffic.status === 'disabled' ? 'Disabled' : 'Collecting'}
                            </Badge>
                        )}
                    </div>
                    <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                        Estimated unique signed-out visitors · Landing pages and DOI/IGSN portals · Europe/Berlin
                    </p>
                </div>

                <ToggleGroup
                    type="single"
                    value={period}
                    variant="outline"
                    className="self-start"
                    aria-label="Public traffic period"
                    onValueChange={(value) => value && setPeriod(value as PublicTrafficPeriod)}
                >
                    <ToggleGroupItem value="4w" aria-label="Show last 4 weeks">
                        Last 4 weeks
                    </ToggleGroupItem>
                    <ToggleGroupItem value="12w" aria-label="Show last 12 weeks">
                        Last 12 weeks
                    </ToggleGroupItem>
                    <ToggleGroupItem value="52w" aria-label="Show last 52 weeks">
                        Last 52 weeks
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {error && (
                <Card className="border-destructive/50">
                    <CardContent className="flex flex-col items-center gap-3 py-8 text-center">
                        <p className="text-sm text-destructive" role="alert">
                            {error}
                        </p>
                        <Button type="button" variant="outline" size="sm" onClick={() => setRetryKey((value) => value + 1)}>
                            <RefreshCw aria-hidden="true" className="mr-2 size-4" /> Try again
                        </Button>
                    </CardContent>
                </Card>
            )}

            {!error && isLoading && !traffic && <TrafficSkeleton />}

            {!error && traffic && (
                <div className={cn('space-y-4', isLoading && 'opacity-60')} aria-busy={isLoading}>
                    {traffic.status === 'disabled' && (
                        <Alert>
                            <AlertDescription>Public traffic collection is disabled in this environment.</AlertDescription>
                        </Alert>
                    )}
                    {traffic.status === 'collecting' && (
                        <Alert>
                            <Clock3 aria-hidden="true" />
                            <AlertDescription>
                                Weekly recommendations will appear after every weekday and hour has at least one complete observation.
                            </AlertDescription>
                        </Alert>
                    )}
                    {traffic.status !== 'disabled' && traffic.effectiveFrom && (
                        <p className="text-sm text-muted-foreground">
                            Evaluated {formatDate(traffic.effectiveFrom, traffic.timezone)}–{formatDate(traffic.to, traffic.timezone)} ·{' '}
                            {traffic.coverage.completeHours.toLocaleString('en-US')} complete hours
                            {traffic.coverage.excludedHours > 0
                                ? ` · ${traffic.coverage.excludedHours.toLocaleString('en-US')} unavailable or incomplete hours excluded`
                                : ''}
                        </p>
                    )}
                    {traffic.status === 'available' && traffic.coverage.lowSampleWarning && (
                        <Alert>
                            <AlertDescription>
                                The pattern is available, but some cells have fewer than four observations and may still change noticeably.
                            </AlertDescription>
                        </Alert>
                    )}
                    {traffic.status === 'available' && (
                        <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                            <RankingCard
                                title="Quietest windows"
                                description="Best candidates for a short planned downtime."
                                cells={traffic.quietest}
                                icon={TrendingDown}
                            />
                            <RankingCard
                                title="Busiest windows"
                                description="Avoid updates during these recurring peaks."
                                cells={traffic.busiest}
                                icon={TrendingUp}
                            />
                        </div>
                    )}
                    {traffic.status !== 'disabled' && <TrafficHeatmap cells={traffic.cells} />}
                    <p className="text-xs text-muted-foreground">
                        The analytics recorder stores a short-lived HMAC identifier only in shared-cache key names; it expires shortly after its UTC
                        hour. Raw IP addresses and user agents are not stored, and only hourly aggregate counts persist in MySQL. Landing page and
                        portal values may overlap and must not be added together.
                    </p>
                </div>
            )}
        </section>
    );
}
