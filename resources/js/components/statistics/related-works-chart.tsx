import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

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
};

type RelatedWorksChartProps = {
    data: RelatedWorksData;
};

export default function RelatedWorksChart({ data }: RelatedWorksChartProps) {
    // Sort distribution by custom order
    const rangeOrder = ['1-10', '11-25', '26-50', '51-100', '101-200', '201-400', '400+'];
    const sortedDistribution = [...data.distribution].sort(
        (a, b) => rangeOrder.indexOf(a.range) - rangeOrder.indexOf(b.range)
    );

    const chartData = sortedDistribution.map((item) => ({
        range: item.range,
        datasets: item.count,
    }));

    return (
        <div className="space-y-6">
            {/* Histogram */}
            <div>
                <h4 className="mb-4 text-sm font-medium">Distribution by Range</h4>
                <ResponsiveContainer width="100%" height={300}>
                    <BarChart data={chartData}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                        <XAxis
                            dataKey="range"
                            className="text-xs"
                            label={{ value: 'Related Works Range', position: 'insideBottom', offset: -5 }}
                        />
                        <YAxis
                            className="text-xs"
                            label={{ value: 'Number of Datasets', angle: -90, position: 'insideLeft' }}
                        />
                        <Tooltip
                            content={({ active, payload }) => {
                                if (active && payload && payload.length) {
                                    return (
                                        <div className="rounded-lg border bg-background p-2 shadow-sm">
                                            <div className="grid gap-2">
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                        Range
                                                    </span>
                                                    <span className="font-bold">
                                                        {payload[0].payload.range} related works
                                                    </span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                        Datasets
                                                    </span>
                                                    <span className="font-bold text-muted-foreground">
                                                        {payload[0].payload.datasets}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                }
                                return null;
                            }}
                        />
                        <Bar dataKey="datasets" fill="hsl(var(--primary))" radius={[4, 4, 0, 0]} />
                    </BarChart>
                </ResponsiveContainer>
            </div>

            {/* Top Datasets Table */}
            <div>
                <h4 className="mb-4 text-sm font-medium">Top 20 Datasets with Most Related Works</h4>
                <div className="max-h-[400px] overflow-y-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted">
                            <tr>
                                <th className="p-2 text-left font-medium">Rank</th>
                                <th className="p-2 text-left font-medium">Identifier</th>
                                <th className="p-2 text-left font-medium">Title</th>
                                <th className="p-2 text-right font-medium">Related Works</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.topDatasets.map((dataset, index) => (
                                <tr key={dataset.id} className="border-t">
                                    <td className="p-2">{index + 1}</td>
                                    <td className="p-2 font-mono text-xs">{dataset.identifier}</td>
                                    <td className="p-2">
                                        {dataset.title
                                            ? dataset.title.length > 60
                                                ? dataset.title.substring(0, 57) + '...'
                                                : dataset.title
                                            : '-'}
                                    </td>
                                    <td className="p-2 text-right font-bold">{dataset.count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
