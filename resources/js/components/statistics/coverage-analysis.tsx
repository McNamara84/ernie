import type { PieLabel } from 'recharts';
import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

type CoverageData = {
    withNoRelatedWorks: number;
    withOnlyIsSupplementTo: number;
    withMultipleTypes: number;
    avgTypesPerDataset: number;
};

type CoverageAnalysisProps = {
    data: CoverageData;
    totalDatasets: number;
};

const COLORS = {
    noRelatedWorks: '#ef4444', // red-500 - critical/missing
    onlyIsSupplementTo: '#f59e0b', // amber-500 - warning/limited
    multipleTypes: '#10b981', // emerald-500 - positive/complete
};

export default function CoverageAnalysis({ data, totalDatasets }: CoverageAnalysisProps) {
    // Guard against division by zero
    const safePercentage = (value: number): string => {
        return totalDatasets > 0 ? ((value / totalDatasets) * 100).toFixed(2) : '0.00';
    };

    const datasetsWithRelatedWorks = totalDatasets - data.withNoRelatedWorks;

    const chartData = [
        {
            name: 'No Related Works',
            value: data.withNoRelatedWorks,
            percentage: safePercentage(data.withNoRelatedWorks),
            color: COLORS.noRelatedWorks,
        },
        {
            name: 'Only IsSupplementTo',
            value: data.withOnlyIsSupplementTo,
            percentage: safePercentage(data.withOnlyIsSupplementTo),
            color: COLORS.onlyIsSupplementTo,
        },
        {
            name: 'Multiple Types',
            value: data.withMultipleTypes,
            percentage: safePercentage(data.withMultipleTypes),
            color: COLORS.multipleTypes,
        },
    ];

    return (
        <div className="space-y-6">
            {/* Pie Chart */}
            <div>
                <h4 className="mb-4 text-sm font-medium">Related Works Coverage Distribution</h4>
                <ResponsiveContainer width="100%" height={300}>
                    <PieChart>
                        <Pie
                            data={chartData}
                            cx="50%"
                            cy="50%"
                            labelLine={false}
                            label={
                                ((props: { name: string; payload: { percentage: string } }) =>
                                    `${props.name}: ${props.payload.percentage}%`) as PieLabel
                            }
                            outerRadius={100}
                            fill="#8884d8"
                            dataKey="value"
                        >
                            {chartData.map((entry, index) => (
                                <Cell key={`cell-${index}`} fill={entry.color} />
                            ))}
                        </Pie>
                        <Tooltip
                            content={({ active, payload }) => {
                                if (active && payload && payload.length) {
                                    const item = payload[0].payload;
                                    return (
                                        <div className="rounded-lg border bg-background p-3 shadow-sm">
                                            <div className="grid gap-2">
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Category</span>
                                                    <span className="font-bold">{item.name}</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Datasets</span>
                                                    <span className="font-bold text-muted-foreground">{item.value.toLocaleString()}</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Percentage</span>
                                                    <span className="font-bold text-muted-foreground">{item.percentage}%</span>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                }
                                return null;
                            }}
                        />
                        <Legend />
                    </PieChart>
                </ResponsiveContainer>
            </div>

            {/* Summary Cards */}
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2">
                        <div className="h-4 w-4 rounded" style={{ backgroundColor: COLORS.noRelatedWorks }} />
                        <h4 className="text-sm font-medium">No Related Works</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{data.withNoRelatedWorks.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">{safePercentage(data.withNoRelatedWorks)}% of total</p>
                </div>

                <div className="rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2">
                        <div className="h-4 w-4 rounded" style={{ backgroundColor: COLORS.onlyIsSupplementTo }} />
                        <h4 className="text-sm font-medium">Only IsSupplementTo</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{data.withOnlyIsSupplementTo.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">{safePercentage(data.withOnlyIsSupplementTo)}% of total</p>
                </div>

                <div className="rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2">
                        <div className="h-4 w-4 rounded" style={{ backgroundColor: COLORS.multipleTypes }} />
                        <h4 className="text-sm font-medium">Multiple Types</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{data.withMultipleTypes.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">{safePercentage(data.withMultipleTypes)}% of total</p>
                </div>

                <div className="rounded-lg border bg-card p-4">
                    <h4 className="text-sm font-medium">Avg. Types per Dataset</h4>
                    <p className="mt-2 text-2xl font-bold">{data.avgTypesPerDataset}</p>
                    <p className="text-sm text-muted-foreground">For datasets with related works</p>
                </div>
            </div>

            {/* Additional Insights */}
            <div className="rounded-lg border bg-card p-4">
                <h4 className="mb-3 text-sm font-medium">Coverage Insights</h4>
                <div className="space-y-3 text-sm">
                    <div className="flex items-start gap-2">
                        <div className="mt-0.5 h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        <p>
                            <span className="font-semibold">{datasetsWithRelatedWorks.toLocaleString()}</span> datasets (
                            {safePercentage(datasetsWithRelatedWorks)}%) have at least one related work entry
                        </p>
                    </div>
                    <div className="flex items-start gap-2">
                        <div className="mt-0.5 h-1.5 w-1.5 rounded-full bg-amber-500" />
                        <p>
                            <span className="font-semibold">{data.withOnlyIsSupplementTo.toLocaleString()}</span> datasets use exclusively the
                            IsSupplementTo relation type
                        </p>
                    </div>
                    <div className="flex items-start gap-2">
                        <div className="mt-0.5 h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        <p>
                            <span className="font-semibold">{data.withMultipleTypes.toLocaleString()}</span> datasets use multiple relation types,
                            indicating rich contextual information
                        </p>
                    </div>
                    <div className="flex items-start gap-2">
                        <div className="mt-0.5 h-1.5 w-1.5 rounded-full bg-blue-500" />
                        <p>
                            On average, datasets with related works reference <span className="font-semibold">{data.avgTypesPerDataset}</span>{' '}
                            different types of relations
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
