import { Bar, BarChart, CartesianGrid, Cell, XAxis, YAxis } from 'recharts';

import { type ChartConfig, ChartContainer, ChartTooltip } from '@/components/ui/chart';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

type RelatedWorksData = {
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
    // Optional new fields from extended statistics
    isSupplementTo?: {
        withIsSupplementTo: number;
        withoutIsSupplementTo: number;
        percentageWith: number;
        percentageWithout: number;
    };
    placeholders?: {
        totalPlaceholders: number;
        datasetsWithPlaceholders: number;
        patterns: Array<{
            pattern: string;
            count: number;
        }>;
    };
    relationTypes?: Array<{
        type: string;
        count: number;
        datasetCount: number;
        percentage: number;
    }>;
    coverage?: {
        withNoRelatedWorks: number;
        withOnlyIsSupplementTo: number;
        withMultipleTypes: number;
        avgTypesPerDataset: number;
    };
    quality?: {
        completeData: number;
        incompleteOrPlaceholder: number;
        percentageComplete: number;
    };
};

type RelatedWorksChartProps = {
    data: RelatedWorksData;
};

const COLORS = [
    '#3b82f6', // blue-500
    '#10b981', // emerald-500
    '#f59e0b', // amber-500
    '#ef4444', // red-500
    '#8b5cf6', // violet-500
    '#ec4899', // pink-500
    '#14b8a6', // teal-500
    '#f97316', // orange-500
    '#06b6d4', // cyan-500
    '#84cc16', // lime-500
];

export default function RelatedWorksChart({ data }: RelatedWorksChartProps) {
    // Sort distribution by custom order
    const rangeOrder = ['1-10', '11-25', '26-50', '51-100', '101-200', '201-400', '400+'];
    const sortedDistribution = [...data.distribution].sort((a, b) => rangeOrder.indexOf(a.range) - rangeOrder.indexOf(b.range));

    const chartData = sortedDistribution.map((item) => ({
        range: item.range,
        datasets: item.count,
    }));

    return (
        <div className="space-y-6">
            {/* Histogram */}
            <div>
                <h4 className="mb-4 text-sm font-medium">Distribution by Range</h4>
                <ChartContainer config={{ datasets: { label: 'Datasets' } } satisfies ChartConfig} className="h-[300px] w-full">
                    <BarChart data={chartData}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis
                            dataKey="range"
                            tickLine={false}
                            axisLine={false}
                            label={{
                                value: 'Related Works Range',
                                position: 'insideBottom',
                                offset: -5,
                            }}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            label={{
                                value: 'Number of Datasets',
                                angle: -90,
                                position: 'insideLeft',
                            }}
                        />
                        <ChartTooltip
                            content={({ active, payload }) => {
                                if (active && payload && payload.length) {
                                    return (
                                        <div className="rounded-lg border border-border/50 bg-background p-2 shadow-xl">
                                            <div className="grid gap-2">
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Range</span>
                                                    <span className="font-bold">{payload[0].payload.range} related works</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Datasets</span>
                                                    <span className="font-bold text-muted-foreground">{payload[0].payload.datasets}</span>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                }
                                return null;
                            }}
                        />
                        <Bar dataKey="datasets" radius={[4, 4, 0, 0]}>
                            {chartData.map((entry, index) => (
                                <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                            ))}
                        </Bar>
                    </BarChart>
                </ChartContainer>
            </div>

            {/* Top Datasets Table */}
            <div>
                <h4 className="mb-4 text-sm font-medium">Top 20 Datasets with Most Related Works</h4>
                <div className="max-h-[400px] overflow-y-auto rounded-md border">
                    <Table>
                        <TableHeader className="sticky top-0 bg-muted">
                            <TableRow>
                                <TableHead>Rank</TableHead>
                                <TableHead>Identifier</TableHead>
                                <TableHead>Title</TableHead>
                                <TableHead className="text-right">Related Works</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.topDatasets.map((dataset, index) => (
                                <TableRow key={dataset.id}>
                                    <TableCell>{index + 1}</TableCell>
                                    <TableCell className="font-mono text-xs">{dataset.identifier}</TableCell>
                                    <TableCell>
                                        {dataset.title ? (dataset.title.length > 60 ? dataset.title.substring(0, 57) + '...' : dataset.title) : '-'}
                                    </TableCell>
                                    <TableCell className="text-right font-bold">{dataset.count}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </div>
    );
}
