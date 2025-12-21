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
    '#f43f5e', // rose-500
    '#0ea5e9', // sky-500
    '#d946ef', // fuchsia-500
    '#64748b', // slate-500
    '#78716c', // stone-500
    '#fb923c', // orange-400
    '#4ade80', // green-400
    '#facc15', // yellow-400
    '#38bdf8', // sky-400
    '#c084fc', // purple-400
    '#f472b6', // pink-400
    '#2dd4bf', // teal-400
    '#fb7185', // rose-400
    '#94a3b8', // slate-400
    '#a8a29e', // stone-400
    '#fdba74', // orange-300
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
                    <Pie data={chartData} cx="50%" cy="50%" labelLine={false} outerRadius={100} fill="#8884d8" dataKey="value">
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
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Identifier Type</span>
                                                <span className="font-bold">{data.name}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Count</span>
                                                <span className="font-bold text-muted-foreground">{data.value.toLocaleString()}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Percentage</span>
                                                <span className="font-bold text-muted-foreground">{data.percentage.toFixed(2)}%</span>
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
                                    <div className="h-3 w-3 rounded-sm" style={{ backgroundColor: COLORS[index % COLORS.length] }} />
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
