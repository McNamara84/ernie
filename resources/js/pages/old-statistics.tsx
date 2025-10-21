import { Head, router } from '@inertiajs/react';
import { RefreshCcw } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { oldStatistics } from '@/routes';
import { type BreadcrumbItem } from '@/types';

import CompletenessGauge from '@/components/statistics/completeness-gauge';
import CuratorChart from '@/components/statistics/curator-chart';
import InstitutionChart from '@/components/statistics/institution-chart';
import LanguageChart from '@/components/statistics/language-chart';
import LicenseChart from '@/components/statistics/license-chart';
import PidUsageChart from '@/components/statistics/pid-usage-chart';
import RelatedWorksChart from '@/components/statistics/related-works-chart';
import ResourceTypeChart from '@/components/statistics/resource-type-chart';
import RoleDistributionChart from '@/components/statistics/role-distribution-chart';
import StatsCard from '@/components/statistics/stats-card';
import TimelineChart from '@/components/statistics/timeline-chart';

type InstitutionStat = {
    name: string;
    rorId: string | null;
    count: number;
};

type RelatedWorksStat = {
    topDatasets: Array<{
        id: number;
        identifier: string;
        title: string | null;
        count: number;
    }>;
    distribution: Array<{
        range: string;
        count: number;
    }>;
};

type PidUsageStat = {
    type: string;
    count: number;
    percentage: number;
};

type CompletenessStat = {
    descriptions: number;
    geographicCoverage: number;
    temporalCoverage: number;
    funding: number;
    orcid: number;
    rorIds: number;
    relatedWorks: number;
};

type CuratorStat = {
    name: string;
    count: number;
};

type RoleStat = {
    role: string;
    count: number;
};

type TimelineStat = {
    publicationsByYear: Array<{
        year: number;
        count: number;
    }>;
    createdByYear: Array<{
        year: number;
        count: number;
    }>;
};

type ResourceTypeStat = {
    type: string;
    count: number;
};

type LanguageStat = {
    language: string;
    count: number;
};

type LicenseStat = {
    name: string;
    count: number;
};

type OverviewStat = {
    totalDatasets: number;
    totalAuthors: number;
    avgAuthorsPerDataset: number;
    avgContributorsPerDataset: number;
    avgRelatedWorks: number;
    oldestDataset: {
        id: number;
        identifier: string;
        year: number;
    } | null;
    newestDataset: {
        id: number;
        identifier: string;
        year: number;
    } | null;
    oldestCreated: {
        id: number;
        identifier: string;
        createdAt: string;
    } | null;
    newestCreated: {
        id: number;
        identifier: string;
        createdAt: string;
    } | null;
};

type Statistics = {
    overview: OverviewStat;
    institutions: InstitutionStat[];
    relatedWorks: RelatedWorksStat;
    pidUsage: PidUsageStat[];
    completeness: CompletenessStat;
    curators: CuratorStat[];
    roles: RoleStat[];
    timeline: TimelineStat;
    resourceTypes: ResourceTypeStat[];
    languages: LanguageStat[];
    licenses: LicenseStat[];
};

type OldStatisticsProps = {
    statistics: Statistics;
    lastUpdated: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Statistics (old)',
        href: oldStatistics().url,
    },
];

export default function OldStatistics({ statistics, lastUpdated }: OldStatisticsProps) {
    const [isRefreshing, setIsRefreshing] = useState(false);

    const handleRefresh = () => {
        setIsRefreshing(true);
        router.visit(oldStatistics({ query: { refresh: '1' } }).url, {
            preserveState: false,
            onFinish: () => setIsRefreshing(false),
        });
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleString('de-DE', {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Statistics (old)" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Old Database Statistics</h1>
                        <p className="text-muted-foreground">
                            Comprehensive analysis of legacy metaworks data
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Last updated: {formatDate(lastUpdated)}
                        </p>
                    </div>
                    <Button
                        onClick={handleRefresh}
                        disabled={isRefreshing}
                        variant="outline"
                        size="sm"
                    >
                        <RefreshCcw className={`mr-2 h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                        Refresh Data
                    </Button>
                </div>

                {/* Overview Stats Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <StatsCard
                        title="Total Datasets"
                        value={statistics.overview.totalDatasets.toLocaleString()}
                        description="Legacy datasets in metaworks"
                    />
                    <StatsCard
                        title="Total Authors"
                        value={statistics.overview.totalAuthors.toLocaleString()}
                        description="Unique authors with Creator role"
                    />
                    <StatsCard
                        title="Avg. Authors"
                        value={statistics.overview.avgAuthorsPerDataset.toString()}
                        description="Average authors per dataset"
                    />
                    <StatsCard
                        title="Avg. Related Works"
                        value={statistics.overview.avgRelatedWorks.toString()}
                        description="Average related identifiers"
                    />
                </div>

                {/* Additional Overview Stats */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <StatsCard
                        title="Avg. Contributors"
                        value={statistics.overview.avgContributorsPerDataset.toString()}
                        description="Average contributors per dataset"
                    />
                    {statistics.overview.oldestDataset && (
                        <StatsCard
                            title="Oldest Publication"
                            value={statistics.overview.oldestDataset.year.toString()}
                            description={`ID: ${statistics.overview.oldestDataset.identifier}`}
                        />
                    )}
                    {statistics.overview.newestDataset && (
                        <StatsCard
                            title="Newest Publication"
                            value={statistics.overview.newestDataset.year.toString()}
                            description={`ID: ${statistics.overview.newestDataset.identifier}`}
                        />
                    )}
                </div>

                {/* Institution Statistics */}
                <Card>
                    <CardHeader>
                        <CardTitle>📊 Top Publishing Institutions</CardTitle>
                        <CardDescription>
                            Institutions with the most published datasets (by affiliations)
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <InstitutionChart data={statistics.institutions} />
                    </CardContent>
                </Card>

                {/* Data Quality Section */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Related Works */}
                    <Card>
                        <CardHeader>
                            <CardTitle>🔗 Related Works Distribution</CardTitle>
                            <CardDescription>
                                Datasets grouped by number of related identifiers
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RelatedWorksChart data={statistics.relatedWorks} />
                        </CardContent>
                    </Card>

                    {/* PID Usage */}
                    <Card>
                        <CardHeader>
                            <CardTitle>🆔 Persistent Identifier Usage</CardTitle>
                            <CardDescription>
                                Distribution of identifier types in related works
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <PidUsageChart data={statistics.pidUsage} />
                        </CardContent>
                    </Card>
                </div>

                {/* Data Completeness */}
                <Card>
                    <CardHeader>
                        <CardTitle>✅ Data Completeness Metrics</CardTitle>
                        <CardDescription>
                            Percentage of datasets with complete metadata fields
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CompletenessGauge data={statistics.completeness} />
                    </CardContent>
                </Card>

                {/* Curator & Role Statistics */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Top Curators */}
                    <Card>
                        <CardHeader>
                            <CardTitle>👤 Top Curators</CardTitle>
                            <CardDescription>
                                Curators by number of datasets they supervised
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <CuratorChart data={statistics.curators} />
                        </CardContent>
                    </Card>

                    {/* Role Distribution */}
                    <Card>
                        <CardHeader>
                            <CardTitle>🎭 Contributor Role Distribution</CardTitle>
                            <CardDescription>Most frequently assigned contributor roles</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RoleDistributionChart data={statistics.roles} />
                        </CardContent>
                    </Card>
                </div>

                {/* Timeline */}
                <Card>
                    <CardHeader>
                        <CardTitle>📅 Publications Timeline</CardTitle>
                        <CardDescription>
                            Dataset publications and creation over time
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <TimelineChart data={statistics.timeline} />
                    </CardContent>
                </Card>

                {/* Additional Statistics */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Resource Types */}
                    <Card>
                        <CardHeader>
                            <CardTitle>📚 Resource Types</CardTitle>
                            <CardDescription>Distribution by resource type</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ResourceTypeChart data={statistics.resourceTypes} />
                        </CardContent>
                    </Card>

                    {/* Languages */}
                    <Card>
                        <CardHeader>
                            <CardTitle>🌍 Languages</CardTitle>
                            <CardDescription>Dataset language distribution</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <LanguageChart data={statistics.languages} />
                        </CardContent>
                    </Card>

                    {/* Licenses */}
                    <Card>
                        <CardHeader>
                            <CardTitle>📄 Top Licenses</CardTitle>
                            <CardDescription>Most used licenses</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <LicenseChart data={statistics.licenses} />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
