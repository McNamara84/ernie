import axios from 'axios';
import { Activity, Cpu, MemoryStick, RefreshCw, Server } from 'lucide-react';
import { useCallback, useEffect, useId, useState } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { type ChartConfig, ChartContainer, ChartTooltip } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

type MetricsPeriod = 'day' | 'week';
type MetricsStatus = 'disabled' | 'collecting' | 'available' | 'stale';
type MetricKey = 'cpu_usage_percent' | 'memory_usage_percent';

interface SystemMetricPoint {
    recorded_at: string;
    cpu_usage_percent: number | null;
    memory_usage_percent: number | null;
}

interface LatestSystemMetrics {
    recorded_at: string;
    cpu_usage_percent: number | null;
    memory_usage_percent: number;
    memory_used_bytes: number;
    memory_total_bytes: number;
}

export interface SystemMetricsResponse {
    period: MetricsPeriod;
    from: string;
    to: string;
    bucket_minutes: number;
    status: MetricsStatus;
    latest: LatestSystemMetrics | null;
    samples: SystemMetricPoint[];
}

interface SystemMetricsPanelProps {
    refreshKey?: number;
}

interface MetricTooltipProps {
    active?: boolean;
    label?: number | string;
    payload?: Array<{ value?: number | string }>;
    metricLabel: string;
}

const statusContent: Record<MetricsStatus, { label: string; description: string }> = {
    disabled: {
        label: 'Disabled',
        description: 'Host VM monitoring is not enabled in this environment.',
    },
    collecting: {
        label: 'Collecting',
        description: 'The history is filling up. CPU data becomes available after two consecutive samples.',
    },
    available: {
        label: 'Live',
        description: 'Host VM metrics are being collected every minute.',
    },
    stale: {
        label: 'Stale',
        description: 'The latest sample is older than three minutes. The scheduler may not be running.',
    },
};

function formatTimestamp(value: number | string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(date);
}

function formatAxisTimestamp(value: string, period: MetricsPeriod): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(undefined, {
        ...(period === 'week' ? { weekday: 'short' as const } : {}),
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return 'Unavailable';
    }

    const gibibytes = bytes / 1024 ** 3;

    return `${gibibytes.toLocaleString(undefined, { maximumFractionDigits: 1 })} GiB`;
}

function MetricTooltip({ active, label, payload, metricLabel }: MetricTooltipProps) {
    if (!active || label === undefined || payload?.[0]?.value === undefined || payload[0].value === null) {
        return null;
    }

    const numericValue = Number(payload[0].value);

    return (
        <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-xs shadow-xl">
            <p className="font-medium">{formatTimestamp(label)}</p>
            <p className="mt-1 flex justify-between gap-6 text-muted-foreground">
                <span>{metricLabel}</span>
                <span className="font-mono font-medium text-foreground">{numericValue.toFixed(1)}%</span>
            </p>
        </div>
    );
}

function MetricChartCard({
    title,
    description,
    metricKey,
    currentValue,
    currentDetail,
    samples,
    period,
    color,
    icon: Icon,
}: {
    title: string;
    description: string;
    metricKey: MetricKey;
    currentValue: number | null;
    currentDetail?: string;
    samples: SystemMetricPoint[];
    period: MetricsPeriod;
    color: string;
    icon: typeof Cpu;
}) {
    const gradientId = `system-metric-${useId().replace(/:/g, '')}`;
    const hasData = samples.some((sample) => sample[metricKey] !== null);
    const chartConfig = {
        value: {
            label: title,
            color,
        },
    } satisfies ChartConfig;

    return (
        <Card>
            <CardHeader className="space-y-2 pb-2">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <Icon aria-hidden="true" className="size-5 text-muted-foreground" />
                        <div>
                            <CardTitle className="text-base">{title}</CardTitle>
                            <CardDescription>{description}</CardDescription>
                        </div>
                    </div>
                    <div className="text-right">
                        <p
                            className="text-2xl font-semibold tabular-nums"
                            aria-label={`${title}: ${currentValue?.toFixed(1) ?? 'unavailable'} percent`}
                        >
                            {currentValue === null ? '—' : `${currentValue.toFixed(1)}%`}
                        </p>
                        {currentDetail && <p className="text-xs text-muted-foreground">{currentDetail}</p>}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                {hasData ? (
                    <ChartContainer config={chartConfig} className="h-64 w-full">
                        <AreaChart data={samples} accessibilityLayer margin={{ top: 8, right: 8, bottom: 0, left: -16 }}>
                            <defs>
                                <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor="var(--color-value)" stopOpacity={0.35} />
                                    <stop offset="95%" stopColor="var(--color-value)" stopOpacity={0.03} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid vertical={false} strokeDasharray="3 3" />
                            <XAxis
                                dataKey="recorded_at"
                                tickLine={false}
                                axisLine={false}
                                minTickGap={32}
                                tickFormatter={(value: string) => formatAxisTimestamp(value, period)}
                            />
                            <YAxis
                                domain={[0, 100]}
                                ticks={[0, 25, 50, 75, 100]}
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(value) => `${value}%`}
                            />
                            <ChartTooltip content={<MetricTooltip metricLabel={title} />} />
                            <Area
                                type="monotone"
                                dataKey={metricKey}
                                connectNulls={false}
                                stroke="var(--color-value)"
                                strokeWidth={2}
                                fill={`url(#${gradientId})`}
                                isAnimationActive={false}
                            />
                        </AreaChart>
                    </ChartContainer>
                ) : (
                    <div className="flex h-64 items-center justify-center rounded-md border border-dashed text-center text-sm text-muted-foreground">
                        No measurements are available for this period yet.
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function MetricsSkeleton() {
    return (
        <div className="grid gap-4 lg:grid-cols-2" aria-label="Loading server utilization">
            {[0, 1].map((item) => (
                <Card key={item}>
                    <CardHeader>
                        <Skeleton className="h-5 w-40" />
                        <Skeleton className="h-4 w-64 max-w-full" />
                    </CardHeader>
                    <CardContent>
                        <Skeleton className="h-64 w-full" />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export default function SystemMetricsPanel({ refreshKey = 0 }: SystemMetricsPanelProps) {
    const [period, setPeriod] = useState<MetricsPeriod>('day');
    const [metrics, setMetrics] = useState<SystemMetricsResponse | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [retryKey, setRetryKey] = useState(0);

    const loadMetrics = useCallback(
        async (signal: AbortSignal): Promise<void> => {
            setIsLoading(true);
            setError(null);

            try {
                const response = await axios.get<SystemMetricsResponse>('/logs/system-metrics', {
                    params: { period },
                    signal,
                });

                if (!signal.aborted) {
                    setMetrics(response.data);
                }
            } catch {
                if (!signal.aborted) {
                    setError('Server utilization could not be loaded.');
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
        void loadMetrics(controller.signal);

        return () => controller.abort();
    }, [loadMetrics, refreshKey, retryKey]);

    const status = metrics?.status ?? 'collecting';
    const statusCopy = statusContent[status];
    const latest = metrics?.latest ?? null;
    const memoryDetail = latest ? `${formatBytes(latest.memory_used_bytes)} of ${formatBytes(latest.memory_total_bytes)}` : undefined;
    const statusDescription = metrics ? statusCopy.description : error ? 'Host VM metrics are currently unavailable.' : 'Loading host VM metrics.';

    return (
        <section className="space-y-4" aria-labelledby="server-utilization-title">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Server aria-hidden="true" className="size-6" />
                        <h2 id="server-utilization-title" className="text-xl font-semibold">
                            Server utilization
                        </h2>
                        {metrics && (
                            <Badge
                                variant={status === 'stale' ? 'destructive' : status === 'available' ? 'default' : 'secondary'}
                                className={cn(status === 'available' && 'bg-emerald-600 text-white')}
                            >
                                <Activity aria-hidden="true" />
                                {statusCopy.label}
                            </Badge>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Entire production VM · {statusDescription}
                        {latest ? ` Last sample: ${formatTimestamp(latest.recorded_at)}.` : ''}
                    </p>
                </div>

                <ToggleGroup
                    type="single"
                    value={period}
                    variant="outline"
                    aria-label="Server utilization period"
                    onValueChange={(value) => value && setPeriod(value as MetricsPeriod)}
                >
                    <ToggleGroupItem value="day" aria-label="Show last 24 hours">
                        Last 24 hours
                    </ToggleGroupItem>
                    <ToggleGroupItem value="week" aria-label="Show last 7 days">
                        Last 7 days
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
                            <RefreshCw aria-hidden="true" className="mr-2 size-4" />
                            Try again
                        </Button>
                    </CardContent>
                </Card>
            )}

            {!error && isLoading && !metrics && <MetricsSkeleton />}

            {!error && metrics && (
                <div className={cn('grid gap-4 lg:grid-cols-2', isLoading && 'opacity-60')} aria-busy={isLoading}>
                    <MetricChartCard
                        title="CPU utilization"
                        description="Average across all logical CPUs"
                        metricKey="cpu_usage_percent"
                        currentValue={latest?.cpu_usage_percent ?? null}
                        samples={metrics.samples}
                        period={period}
                        color="var(--chart-1)"
                        icon={Cpu}
                    />
                    <MetricChartCard
                        title="Memory utilization"
                        description="Used VM memory excluding readily reclaimable cache"
                        metricKey="memory_usage_percent"
                        currentValue={latest?.memory_usage_percent ?? null}
                        currentDetail={memoryDetail}
                        samples={metrics.samples}
                        period={period}
                        color="var(--chart-2)"
                        icon={MemoryStick}
                    />
                </div>
            )}
        </section>
    );
}
