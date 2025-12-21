import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

type ResourceTypeData = {
    type: string;
    count: number;
};

type ResourceTypeChartProps = {
    data: ResourceTypeData[];
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
];

export default function ResourceTypeChart({ data }: ResourceTypeChartProps) {
    const chartData = data.map((item, index) => ({
        name: item.type,
        value: item.count,
        color: COLORS[index % COLORS.length],
    }));

    return (
        <div className="space-y-4">
            <ResponsiveContainer width="100%" height={250}>
                <PieChart>
                    <Pie data={chartData} cx="50%" cy="50%" outerRadius={80} fill="#8884d8" dataKey="value">
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
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Type</span>
                                                <span className="font-bold">{data.name}</span>
                                            </div>
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Count</span>
                                                <span className="font-bold text-muted-foreground">{data.value.toLocaleString()}</span>
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

            <div className="rounded-md border">
                <table className="w-full text-sm">
                    <tbody>
                        {data.map((item, index) => (
                            <tr key={item.type} className="border-t first:border-t-0">
                                <td className="flex items-center gap-2 p-2">
                                    <div
                                        className="h-3 w-3 rounded-sm"
                                        style={{
                                            backgroundColor: COLORS[index % COLORS.length],
                                        }}
                                    />
                                    {item.type}
                                </td>
                                <td className="p-2 text-right">{item.count.toLocaleString()}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
