import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type RelationType = {
    type: string;
    count: number;
    datasetCount: number;
    percentage: number;
};

type RelationTypesChartProps = {
    data: RelationType[];
    limit?: number;
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
    '#6366f1', // indigo-500
    '#a855f7', // purple-500
    '#22c55e', // green-500
    '#eab308', // yellow-500
    '#dc2626', // red-600
    '#0ea5e9', // sky-500
    '#d946ef', // fuchsia-500
    '#fb923c', // orange-400
    '#f43f5e', // rose-500
    '#64748b', // slate-500
];

export default function RelationTypesChart({ data, limit = 15 }: RelationTypesChartProps) {
    // Take top N relation types by count
    const displayData = data.slice(0, limit);

    const chartData = displayData.map((item) => ({
        type: item.type,
        occurrences: item.count,
        datasets: item.datasetCount,
        percentage: item.percentage,
    }));

    return (
        <div className="space-y-6">
            {/* Bar Chart */}
            <div>
                <h4 className="mb-4 text-sm font-medium">Top {limit} Relation Types by Occurrences</h4>
                <ResponsiveContainer width="100%" height={400}>
                    <BarChart data={chartData} layout="vertical">
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                        <XAxis
                            type="number"
                            className="text-xs"
                            label={{
                                value: 'Number of Occurrences',
                                position: 'insideBottom',
                                offset: -5,
                            }}
                        />
                        <YAxis type="category" dataKey="type" className="text-xs" width={120} />
                        <Tooltip
                            content={({ active, payload }) => {
                                if (active && payload && payload.length) {
                                    const item = payload[0].payload;
                                    return (
                                        <div className="rounded-lg border bg-background p-3 shadow-sm">
                                            <div className="grid gap-2">
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Relation Type</span>
                                                    <span className="font-bold">{item.type}</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Total Occurrences</span>
                                                    <span className="font-bold text-muted-foreground">{item.occurrences.toLocaleString()}</span>
                                                </div>
                                                <div className="flex flex-col">
                                                    <span className="text-[0.70rem] text-muted-foreground uppercase">Unique Datasets</span>
                                                    <span className="font-bold text-muted-foreground">{item.datasets.toLocaleString()}</span>
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
                        <Bar dataKey="occurrences" radius={[0, 4, 4, 0]}>
                            {chartData.map((entry, index) => (
                                <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                            ))}
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>

            {/* Detailed Table */}
            <div>
                <h4 className="mb-4 text-sm font-medium">All Relation Types - Detailed View</h4>
                <div className="max-h-[500px] overflow-y-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted">
                            <tr>
                                <th className="p-2 text-left font-medium">Rank</th>
                                <th className="p-2 text-left font-medium">Relation Type</th>
                                <th className="p-2 text-right font-medium">Occurrences</th>
                                <th className="p-2 text-right font-medium">Datasets</th>
                                <th className="p-2 text-right font-medium">% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((item, index) => (
                                <tr
                                    key={item.type}
                                    className={`border-t ${item.type === 'IsSupplementTo' ? 'bg-emerald-50 font-semibold dark:bg-emerald-950' : ''}`}
                                >
                                    <td className="p-2">{index + 1}</td>
                                    <td className="p-2 font-mono text-xs">
                                        {item.type}
                                        {item.type === 'IsSupplementTo' && (
                                            <span className="ml-2 rounded bg-emerald-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                                                TARGET
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-2 text-right">{item.count.toLocaleString()}</td>
                                    <td className="p-2 text-right">{item.datasetCount.toLocaleString()}</td>
                                    <td className="p-2 text-right font-bold">{item.percentage}%</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
