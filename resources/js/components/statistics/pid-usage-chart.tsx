import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

type PidUsageData = {
    type: string;
    count: number;
    percentage: number;
};

type PidUsageChartProps = {
    data: PidUsageData[];
};

const COLORS = [
    'hsl(var(--chart-1))',
    'hsl(var(--chart-2))',
    'hsl(var(--chart-3))',
    'hsl(var(--chart-4))',
    'hsl(var(--chart-5))',
    'hsl(221.2 83.2% 53.3%)', // Blue
    'hsl(142.1 76.2% 36.3%)', // Green
    'hsl(24.6 95% 53.1%)', // Orange
    'hsl(262.1 83.3% 57.8%)', // Purple
    'hsl(346.8 77.2% 49.8%)', // Red
];

export default function PidUsageChart({ data }: PidUsageChartProps) {
    const chartData = data.map((item, index) => ({
        name: item.type,
        value: item.count,
        percentage: item.percentage,
        color: COLORS[index % COLORS.length],
    }));

    return (
        <div className="space-y-4">
            <ResponsiveContainer width="100%" height={300}>
                <PieChart>
                    <Pie
                        data={chartData}
                        cx="50%"
                        cy="50%"
                        labelLine={false}
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
                                    <div className="rounded-lg border bg-background p-2 shadow-sm">
                                        <div className="grid gap-2">
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                    Identifier Type
                                                </span>
                                                <span className="font-bold">{data.name}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                    Count
                                                </span>
                                                <span className="font-bold text-muted-foreground">
                                                    {data.value.toLocaleString()}
                                                </span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                    Percentage
                                                </span>
                                                <span className="font-bold text-muted-foreground">
                                                    {data.percentage.toFixed(2)}%
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                );
                            }
                            return null;
                        }}
                    />
                </PieChart>
            </ResponsiveContainer>

            {/* Legend Table */}
            <div className="max-h-[300px] overflow-y-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-muted">
                        <tr>
                            <th className="p-2 text-left font-medium">Type</th>
                            <th className="p-2 text-right font-medium">Count</th>
                            <th className="p-2 text-right font-medium">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.map((item, index) => (
                            <tr key={item.type} className="border-t">
                                <td className="flex items-center gap-2 p-2">
                                    <div
                                        className="h-3 w-3 rounded-sm"
                                        style={{ backgroundColor: COLORS[index % COLORS.length] }}
                                    />
                                    {item.type}
                                </td>
                                <td className="p-2 text-right">{item.count.toLocaleString()}</td>
                                <td className="p-2 text-right">{item.percentage.toFixed(2)}%</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
