import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

type RoleData = {
    role: string;
    count: number;
};

type RoleDistributionChartProps = {
    data: RoleData[];
};

const COLORS = [
    'hsl(var(--chart-1))',
    'hsl(var(--chart-2))',
    'hsl(var(--chart-3))',
    'hsl(var(--chart-4))',
    'hsl(var(--chart-5))',
    'hsl(221.2 83.2% 53.3%)',
    'hsl(142.1 76.2% 36.3%)',
    'hsl(24.6 95% 53.1%)',
    'hsl(262.1 83.3% 57.8%)',
    'hsl(346.8 77.2% 49.8%)',
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
                                                    Role
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
                                    <div
                                        className="h-3 w-3 rounded-sm"
                                        style={{ backgroundColor: COLORS[index % COLORS.length] }}
                                    />
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
