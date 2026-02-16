import { Cell, Pie, PieChart } from 'recharts';

import { type ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

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
            <ChartContainer config={{ value: { label: 'Count' } } satisfies ChartConfig} className="mx-auto h-[300px] w-full">
                <PieChart>
                    <Pie data={chartData} cx="50%" cy="50%" labelLine={false} outerRadius={100} fill="#8884d8" dataKey="value">
                        {chartData.map((entry, index) => (
                            <Cell key={`cell-${index}`} fill={entry.color} />
                        ))}
                    </Pie>
                    <ChartTooltip content={<ChartTooltipContent nameKey="name" />} />
                </PieChart>
            </ChartContainer>

            <div className="max-h-[250px] overflow-y-auto rounded-md border">
                <Table>
                    <TableHeader className="sticky top-0 bg-muted">
                        <TableRow>
                            <TableHead>Role</TableHead>
                            <TableHead className="text-right">Count</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.map((item, index) => (
                            <TableRow key={item.role}>
                                <TableCell className="flex items-center gap-2">
                                    <div className="h-3 w-3 rounded-sm" style={{ backgroundColor: COLORS[index % COLORS.length] }} />
                                    {item.role}
                                </TableCell>
                                <TableCell className="text-right">{item.count.toLocaleString()}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
