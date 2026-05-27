import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

type StatisticsOverview = {
    totalPageViews: number;
    totalDownloadClicks: number;
    totalPortalSearches: number;
    trackedLandingPages: number;
};

type StatisticsTrends = {
    days: string[];
    pageViews: number[];
    downloadClicks: number[];
    portalSearches: number[];
};

type RankedLandingPage = {
    landingPageId: number;
    title: string;
    identifier: string;
    resourceTypeLabel: string;
    total: number;
    publicUrl: string;
    isExternal: boolean;
};

type SearchTermStat = {
    term: string;
    total: number;
};

type TypeSplit = {
    resourcePageViews: number;
    physicalObjectPageViews: number;
    resourceDownloadClicks: number;
    physicalObjectDownloadClicks: number;
};

type StatisticsPageProps = {
    overview: StatisticsOverview;
    trends: StatisticsTrends;
    topLandingPagesByViews: RankedLandingPage[];
    topLandingPagesByDownloads: RankedLandingPage[];
    portalSearchTerms: SearchTermStat[];
    typeSplit: TypeSplit;
    lastUpdated: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Statistics',
        href: '/statistics',
    },
];

function formatCompactNumber(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatDayLabel(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
}

function maxValue(values: number[]): number {
    return values.length > 0 ? Math.max(...values, 1) : 1;
}

function OverviewCard({ title, value, description }: { title: string; value: number; description: string }) {
    return (
        <Card className="border-border/70 bg-card/80 backdrop-blur">
            <CardHeader className="pb-3">
                <CardDescription>{title}</CardDescription>
                <CardTitle className="text-3xl font-semibold text-foreground">{formatCompactNumber(value)}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm text-muted-foreground">{description}</p>
            </CardContent>
        </Card>
    );
}

function TrendCard({ title, description, days, values, toneClass }: { title: string; description: string; days: string[]; values: number[]; toneClass: string }) {
    const peak = maxValue(values);

    return (
        <Card className="border-border/70 bg-card/80 backdrop-blur">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                {values.some((value) => value > 0) ? (
                    <div className="grid grid-cols-14 gap-2" data-testid={`trend-${title.toLowerCase().replace(/\s+/g, '-')}`}>
                        {days.map((day, index) => (
                            <div key={day} className="flex min-w-0 flex-col items-center gap-2">
                                <div className="flex h-32 w-full items-end rounded-xl bg-muted/50 px-1 py-1">
                                    <div
                                        className={`w-full rounded-md ${toneClass}`}
                                        style={{ height: `${Math.max((values[index] / peak) * 100, values[index] > 0 ? 10 : 0)}%` }}
                                        aria-label={`${title} on ${day}: ${values[index]}`}
                                    />
                                </div>
                                <div className="text-center">
                                    <p className="text-sm font-medium text-foreground">{formatCompactNumber(values[index])}</p>
                                    <p className="text-xs text-muted-foreground">{formatDayLabel(day)}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="rounded-2xl border border-dashed border-border/70 bg-muted/30 p-6 text-sm text-muted-foreground">
                        No tracked activity yet.
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function RankingCard({ title, description, items, emptyMessage }: { title: string; description: string; items: RankedLandingPage[]; emptyMessage: string }) {
    const peak = maxValue(items.map((item) => item.total));

    return (
        <Card className="border-border/70 bg-card/80 backdrop-blur">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {items.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border/70 bg-muted/30 p-6 text-sm text-muted-foreground">
                        {emptyMessage}
                    </div>
                ) : (
                    items.map((item, index) => (
                        <a
                            key={`${item.landingPageId}-${item.total}`}
                            href={item.publicUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="block rounded-2xl border border-border/60 bg-background/80 p-4 transition-colors hover:border-gfz-primary/40 hover:bg-muted/40"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">#{index + 1}</Badge>
                                        <Badge variant="secondary">{item.resourceTypeLabel}</Badge>
                                        {item.isExternal && <Badge variant="outline">External</Badge>}
                                    </div>
                                    <h3 className="text-base font-semibold text-foreground">{item.title}</h3>
                                    <p className="text-sm text-muted-foreground">{item.identifier}</p>
                                </div>
                                <p className="text-xl font-semibold text-gfz-primary">{formatCompactNumber(item.total)}</p>
                            </div>
                            <div className="mt-4 h-2 rounded-full bg-muted">
                                <div
                                    className="h-2 rounded-full bg-gradient-to-r from-gfz-primary to-sky-500"
                                    style={{ width: `${(item.total / peak) * 100}%` }}
                                />
                            </div>
                        </a>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function SearchTermsCard({ items }: { items: SearchTermStat[] }) {
    const peak = maxValue(items.map((item) => item.total));

    return (
        <Card className="border-border/70 bg-card/80 backdrop-blur">
            <CardHeader>
                <CardTitle>Top Portal Search Terms</CardTitle>
                <CardDescription>Normalized terms submitted through the portal search box.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {items.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-border/70 bg-muted/30 p-6 text-sm text-muted-foreground">
                        No portal searches have been tracked yet.
                    </div>
                ) : (
                    items.map((item) => (
                        <div key={item.term} className="rounded-2xl border border-border/60 bg-background/80 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium text-foreground">{item.term}</p>
                                    <p className="text-xs text-muted-foreground">Search submissions</p>
                                </div>
                                <Badge variant="secondary">{formatCompactNumber(item.total)}</Badge>
                            </div>
                            <div className="mt-3 h-2 rounded-full bg-muted">
                                <div
                                    className="h-2 rounded-full bg-gradient-to-r from-amber-500 to-rose-500"
                                    style={{ width: `${(item.total / peak) * 100}%` }}
                                />
                            </div>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function TypeSplitCard({ title, pageViews, downloadClicks }: { title: string; pageViews: number; downloadClicks: number }) {
    return (
        <Card className="border-border/70 bg-card/80 backdrop-blur">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>Engagement split by publication type.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
                <div className="rounded-2xl bg-muted/40 p-4">
                    <p className="text-sm text-muted-foreground">Page views</p>
                    <p className="mt-2 text-2xl font-semibold text-foreground">{formatCompactNumber(pageViews)}</p>
                </div>
                <div className="rounded-2xl bg-muted/40 p-4">
                    <p className="text-sm text-muted-foreground">Download clicks</p>
                    <p className="mt-2 text-2xl font-semibold text-foreground">{formatCompactNumber(downloadClicks)}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export default function Statistics({ overview, trends, topLandingPagesByViews, topLandingPagesByDownloads, portalSearchTerms, typeSplit, lastUpdated }: StatisticsPageProps) {
    const hasAnyAnalytics = overview.totalPageViews > 0 || overview.totalDownloadClicks > 0 || overview.totalPortalSearches > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Statistics" />

            <div className="flex flex-1 flex-col gap-6 rounded-xl bg-gradient-to-b from-background via-background to-muted/20 p-4 md:p-6">
                <section className="overflow-hidden rounded-3xl border border-border/70 bg-gradient-to-br from-gfz-primary/10 via-background to-sky-100/50 p-6 shadow-sm dark:to-gfz-primary/15">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div className="space-y-3">
                            <Badge variant="secondary" className="bg-white/70 text-gfz-primary dark:bg-white/10 dark:text-sky-100">
                                Go-live analytics
                            </Badge>
                            <div className="space-y-2">
                                <h1 className="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">Public engagement statistics</h1>
                                <p className="max-w-3xl text-sm leading-6 text-muted-foreground md:text-base">
                                    This dashboard tracks published landing page views, file download clicks, and explicit portal search submissions.
                                    Review and preview traffic is excluded.
                                </p>
                            </div>
                        </div>
                        <div className="rounded-2xl border border-white/50 bg-white/60 px-4 py-3 text-sm text-muted-foreground shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/5">
                            Last updated: {formatDateTime(lastUpdated)}
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <OverviewCard title="Landing page views" value={overview.totalPageViews} description="Published landing page requests tracked since go-live." />
                    <OverviewCard title="Download clicks" value={overview.totalDownloadClicks} description="Clicks on real download targets in the Files section." />
                    <OverviewCard title="Portal searches" value={overview.totalPortalSearches} description="Explicit submissions from the public portal search slot." />
                    <OverviewCard title="Tracked landing pages" value={overview.trackedLandingPages} description="Published landing pages with recorded engagement data." />
                </section>

                {!hasAnyAnalytics && (
                    <section>
                        <Card className="border-dashed border-border/80 bg-card/60">
                            <CardContent className="p-6 text-sm text-muted-foreground">
                                No analytics have been recorded yet. Metrics start at go-live and will fill in as published landing pages and portal searches receive traffic.
                            </CardContent>
                        </Card>
                    </section>
                )}

                <section className="grid gap-4 xl:grid-cols-3">
                    <TrendCard
                        title="Landing page views"
                        description="Daily published landing page traffic over the last 14 days."
                        days={trends.days}
                        values={trends.pageViews}
                        toneClass="bg-gradient-to-t from-gfz-primary to-sky-500"
                    />
                    <TrendCard
                        title="Download clicks"
                        description="Daily file download clicks from the Files section."
                        days={trends.days}
                        values={trends.downloadClicks}
                        toneClass="bg-gradient-to-t from-emerald-500 to-teal-400"
                    />
                    <TrendCard
                        title="Portal searches"
                        description="Daily explicit portal search submissions."
                        days={trends.days}
                        values={trends.portalSearches}
                        toneClass="bg-gradient-to-t from-amber-500 to-rose-500"
                    />
                </section>

                <section className="grid gap-4 xl:grid-cols-2">
                    <RankingCard
                        title="Top landing pages by views"
                        description="Published landing pages with the most recorded public traffic."
                        items={topLandingPagesByViews}
                        emptyMessage="No landing page views have been recorded yet."
                    />
                    <RankingCard
                        title="Top landing pages by download clicks"
                        description="Published landing pages whose Files section gets the most download activity."
                        items={topLandingPagesByDownloads}
                        emptyMessage="No download clicks have been recorded yet."
                    />
                </section>

                <section className="grid gap-4 xl:grid-cols-[1.35fr_1fr]">
                    <div className="grid gap-4 md:grid-cols-2">
                        <TypeSplitCard title="Resources" pageViews={typeSplit.resourcePageViews} downloadClicks={typeSplit.resourceDownloadClicks} />
                        <TypeSplitCard title="Physical Objects" pageViews={typeSplit.physicalObjectPageViews} downloadClicks={typeSplit.physicalObjectDownloadClicks} />
                    </div>
                    <SearchTermsCard items={portalSearchTerms} />
                </section>
            </div>
        </AppLayout>
    );
}