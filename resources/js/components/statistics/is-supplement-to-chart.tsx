import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

type IsSupplementToData = {
    withIsSupplementTo: number;
    withoutIsSupplementTo: number;
    percentageWith: number;
    percentageWithout: number;
};

type IsSupplementToChartProps = {
    data: IsSupplementToData;
};

const COLORS = {
    with: '#10b981', // emerald-500 - positive/has the relation
    without: '#f59e0b', // amber-500 - warning/missing
};

export default function IsSupplementToChart({ data }: IsSupplementToChartProps) {
    const chartData = [
        {
            name: 'With IsSupplementTo',
            value: data.withIsSupplementTo,
            percentage: data.percentageWith,
            color: COLORS.with,
        },
        {
            name: 'Without IsSupplementTo',
            value: data.withoutIsSupplementTo,
            percentage: data.percentageWithout,
            color: COLORS.without,
        },
    ];

    return (
        <div className="space-y-4">
            <ResponsiveContainer width="100%" height={300}>
                <PieChart>
                    <Pie
                        data={chartData}
                        cx="50%"
                        cy="50%"
                        labelLine={false}
                        label={({ name, percentage }) => `${name}: ${percentage}%`}
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
                                const data = payload[0].payload;
                                return (
                                    <div className="rounded-lg border bg-background p-3 shadow-sm">
                                        <div className="grid gap-2">
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Category</span>
                                                <span className="font-bold">{data.name}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Datasets</span>
                                                <span className="font-bold text-muted-foreground">{data.value.toLocaleString()}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Percentage</span>
                                                <span className="font-bold text-muted-foreground">{data.percentage}%</span>
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

            {/* Summary Cards */}
            <div className="grid gap-4 md:grid-cols-2">
                <div className="rounded-lg border p-4">
                    <div className="flex items-center gap-2">
                        <div className="h-4 w-4 rounded" style={{ backgroundColor: COLORS.with }} />
                        <h4 className="text-sm font-medium">With IsSupplementTo</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{data.withIsSupplementTo.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">{data.percentageWith}% of all datasets</p>
                </div>

                <div className="rounded-lg border p-4">
                    <div className="flex items-center gap-2">
                        <div className="h-4 w-4 rounded" style={{ backgroundColor: COLORS.without }} />
                        <h4 className="text-sm font-medium">Without IsSupplementTo</h4>
                    </div>
                    <p className="mt-2 text-2xl font-bold">{data.withoutIsSupplementTo.toLocaleString()}</p>
                    <p className="text-sm text-muted-foreground">{data.percentageWithout}% of all datasets</p>
                </div>
            </div>
        </div>
    );
}
