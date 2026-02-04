import { Head, router } from '@inertiajs/react';
import { RefreshCcw } from 'lucide-react';
import { useState } from 'react';

import { Spinner } from '@/components/ui/spinner';

import AbstractAnalysis from '@/components/statistics/abstract-analysis';
import AffiliationStatsCard from '@/components/statistics/affiliation-stats-card';
import CompletenessGauge from '@/components/statistics/completeness-gauge';
import CoverageAnalysis from '@/components/statistics/coverage-analysis';
import CreationTimeChart from '@/components/statistics/creation-time-chart';
import CuratorChart from '@/components/statistics/curator-chart';
import CurrentYearChart from '@/components/statistics/current-year-chart';
import DataQualityIndicators from '@/components/statistics/data-quality-indicators';
import DescriptionTypeStats from '@/components/statistics/description-type-stats';
import IdentifierStatsCard from '@/components/statistics/identifier-stats-card';
import InstitutionChart from '@/components/statistics/institution-chart';
import IsSupplementToChart from '@/components/statistics/is-supplement-to-chart';
import KeywordTable from '@/components/statistics/keyword-table';
import LanguageChart from '@/components/statistics/language-chart';
import LicenseChart from '@/components/statistics/license-chart';
import PidUsageChart from '@/components/statistics/pid-usage-chart';
import PublicationYearChart from '@/components/statistics/publication-year-chart';
import RelatedWorksChart from '@/components/statistics/related-works-chart';
import RelationTypesChart from '@/components/statistics/relation-types-chart';
import ResourceTypeChart from '@/components/statistics/resource-type-chart';
import RoleDistributionChart from '@/components/statistics/role-distribution-chart';
import StatsCard from '@/components/statistics/stats-card';
import TimelineChart from '@/components/statistics/timeline-chart';
import TopDatasetsByRelationType from '@/components/statistics/top-datasets-by-relation-type';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { oldStatistics } from '@/routes';
import { type BreadcrumbItem } from '@/types';

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
    isSupplementTo: {
        withIsSupplementTo: number;
        withoutIsSupplementTo: number;
        percentageWith: number;
        percentageWithout: number;
    };
    placeholders: {
        totalPlaceholders: number;
        datasetsWithPlaceholders: number;
        patterns: Array<{
            pattern: string;
            count: number;
        }>;
    };
    relationTypes: Array<{
        type: string;
        count: number;
        datasetCount: number;
        percentage: number;
    }>;
    coverage: {
        withNoRelatedWorks: number;
        withOnlyIsSupplementTo: number;
        withMultipleTypes: number;
        avgTypesPerDataset: number;
    };
    quality: {
        completeData: number;
        incompleteOrPlaceholder: number;
        percentageComplete: number;
    };
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

type IdentifierStat = {
    ror: {
        count: number;
        total: number;
        percentage: number;
    };
    orcid: {
        count: number;
        total: number;
        percentage: number;
    };
};

type CurrentYearStat = {
    year: number;
    total: number;
    monthly: Array<{
        month: number;
        count: number;
    }>;
};

type AffiliationStat = {
    max_per_agent: number;
    avg_per_agent: number;
};

type KeywordStat = {
    free: Array<{
        keyword: string;
        count: number;
    }>;
    controlled: Array<{
        keyword: string;
        count: number;
    }>;
};

type CreationTimeStat = {
    hour: number;
    count: number;
};

type DescriptionStat = {
    by_type: Array<{
        type_id: string; // Changed from number to string
        count: number;
    }>;
    longest_abstract: {
        length: number;
        preview: string;
    } | null;
    shortest_abstract: {
        length: number;
        preview: string;
    } | null;
};

type PublicationYearStat = {
    year: number;
    count: number;
};

type TopDatasetsByRelationTypeStat = {
    [relationType: string]: Array<{
        id: number;
        identifier: string;
        title: string | null;
        count: number;
    }>;
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
    identifiers: IdentifierStat;
    current_year: CurrentYearStat;
    affiliations: AffiliationStat;
    keywords: KeywordStat;
    creation_time: CreationTimeStat[];
    descriptions: DescriptionStat;
    publication_years: PublicationYearStat[];
    topDatasetsByRelationType: TopDatasetsByRelationTypeStat;
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
                        <p className="text-muted-foreground">Comprehensive analysis of legacy metaworks data</p>
                        <p className="mt-1 text-sm text-muted-foreground">Last updated: {formatDate(lastUpdated)}</p>
                    </div>
                    <Button onClick={handleRefresh} disabled={isRefreshing} variant="outline" size="sm">
                        {isRefreshing ? <Spinner size="sm" className="mr-2" /> : <RefreshCcw className="mr-2 h-4 w-4" />}
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
                        <CardDescription>Institutions with the most published datasets (by affiliations)</CardDescription>
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
                            <CardDescription>Datasets grouped by number of related identifiers</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RelatedWorksChart data={statistics.relatedWorks} />
                        </CardContent>
                    </Card>

                    {/* PID Usage */}
                    <Card>
                        <CardHeader>
                            <CardTitle>🆔 Persistent Identifier Usage</CardTitle>
                            <CardDescription>Distribution of identifier types in related works</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <PidUsageChart data={statistics.pidUsage} />
                        </CardContent>
                    </Card>
                </div>

                {/* NEW: Related Works Quality Analysis Section */}
                <div className="space-y-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight">🔍 Related Works Quality Analysis</h2>
                        <p className="text-muted-foreground">Detailed breakdown of relation types, IsSupplementTo usage, and data quality metrics</p>
                    </div>

                    {/* IsSupplementTo Analysis */}
                    <Card>
                        <CardHeader>
                            <CardTitle>📑 IsSupplementTo Relation Analysis</CardTitle>
                            <CardDescription>Distribution of datasets with/without the IsSupplementTo relation type</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <IsSupplementToChart data={statistics.relatedWorks.isSupplementTo} />
                        </CardContent>
                    </Card>

                    {/* Relation Types Distribution */}
                    <Card>
                        <CardHeader>
                            <CardTitle>📊 All Relation Types Distribution</CardTitle>
                            <CardDescription>
                                Complete breakdown of all {statistics.relatedWorks.relationTypes.length} relation types used in the legacy database
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RelationTypesChart data={statistics.relatedWorks.relationTypes} />
                        </CardContent>
                    </Card>

                    {/* Data Quality Indicators */}
                    <Card>
                        <CardHeader>
                            <CardTitle>✅ Data Quality Metrics</CardTitle>
                            <CardDescription>Analysis of placeholder values and overall data completeness</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DataQualityIndicators placeholders={statistics.relatedWorks.placeholders} quality={statistics.relatedWorks.quality} />
                        </CardContent>
                    </Card>

                    {/* Coverage Analysis */}
                    <Card>
                        <CardHeader>
                            <CardTitle>📈 Coverage Analysis</CardTitle>
                            <CardDescription>Overview of datasets by related works coverage level</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <CoverageAnalysis data={statistics.relatedWorks.coverage} totalDatasets={statistics.overview.totalDatasets} />
                        </CardContent>
                    </Card>

                    {/* Top Datasets by Relation Type */}
                    <TopDatasetsByRelationType data={statistics.topDatasetsByRelationType} />
                </div>

                {/* Data Completeness */}
                <Card>
                    <CardHeader>
                        <CardTitle>✅ Data Completeness Metrics</CardTitle>
                        <CardDescription>Percentage of datasets with complete metadata fields</CardDescription>
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
                            <CardDescription>Curators by number of datasets they supervised</CardDescription>
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
                        <CardDescription>Dataset publications and creation over time</CardDescription>
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

                {/* Identifier Statistics */}
                <Card>
                    <CardHeader>
                        <CardTitle>🆔 ROR & ORCID Identifier Coverage</CardTitle>
                        <CardDescription>Percentage of affiliations with ROR-IDs and authors/contributors with ORCIDs</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <IdentifierStatsCard data={statistics.identifiers} />
                    </CardContent>
                </Card>

                {/* Current Year Publications */}
                <Card>
                    <CardHeader>
                        <CardTitle>📆 Publications in {statistics.current_year.year}</CardTitle>
                        <CardDescription>Monthly breakdown of current year publications</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CurrentYearChart data={statistics.current_year} />
                    </CardContent>
                </Card>

                {/* Affiliation Statistics */}
                <Card>
                    <CardHeader>
                        <CardTitle>🏢 Affiliation Statistics</CardTitle>
                        <CardDescription>Maximum and average affiliations per author/contributor</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <AffiliationStatsCard data={statistics.affiliations} />
                    </CardContent>
                </Card>

                {/* Keywords */}
                <KeywordTable data={statistics.keywords} />

                {/* Creation Time Analysis */}
                <Card>
                    <CardHeader>
                        <CardTitle>🕐 Dataset Creation by Hour of Day</CardTitle>
                        <CardDescription>When datasets were created (by hour, 0-23)</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CreationTimeChart data={statistics.creation_time} />
                    </CardContent>
                </Card>

                {/* Description Statistics */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>📝 Description Types</CardTitle>
                            <CardDescription>Distribution by description type</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DescriptionTypeStats data={statistics.descriptions.by_type} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>📄 Abstract Analysis</CardTitle>
                            <CardDescription>Longest and shortest abstracts</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <AbstractAnalysis
                                data={{
                                    longest_abstract: statistics.descriptions.longest_abstract,
                                    shortest_abstract: statistics.descriptions.shortest_abstract,
                                }}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* Publication Year Distribution */}
                <Card>
                    <CardHeader>
                        <CardTitle>📊 Publication Year Distribution</CardTitle>
                        <CardDescription>Number of datasets by publication year over time</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <PublicationYearChart data={statistics.publication_years} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
