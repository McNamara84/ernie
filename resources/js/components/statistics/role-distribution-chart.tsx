import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

type RoleData = {
    role: string;
    count: number;
};

type RoleDistributionChartProps = {
    data: RoleData[];
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
];

export default function RoleDistributionChart({ data }: RoleDistributionChartProps) {
    const chartData = data.map((item, index) => ({
        name: item.role,
        value: item.count,
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
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">Role</span>
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

            <div className="max-h-[250px] overflow-y-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-muted">
                        <tr>
                            <th className="p-2 text-left font-medium">Role</th>
                            <th className="p-2 text-right font-medium">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.map((item, index) => (
                            <tr key={item.role} className="border-t">
                                <td className="flex items-center gap-2 p-2">
                                    <div className="h-3 w-3 rounded-sm" style={{ backgroundColor: COLORS[index % COLORS.length] }} />
                                    {item.role}
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
