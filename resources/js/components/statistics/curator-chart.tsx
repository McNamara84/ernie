import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, XAxis, YAxis } from 'recharts';

import { type ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';

type CuratorData = {
    name: string;
    count: number;
};

type CuratorChartProps = {
    data: CuratorData[];
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
];

export default function CuratorChart({ data }: CuratorChartProps) {
    const chartData = data.map((item) => ({
        name: item.name,
        datasets: item.count,
    }));

    const chartConfig = useMemo(
        () =>
            ({
                datasets: { label: 'Datasets Curated' },
                ...Object.fromEntries(chartData.map((item, i) => [item.name, { label: item.name, color: COLORS[i % COLORS.length] }])),
            }) satisfies ChartConfig,
        [chartData],
    );

    return (
        <ChartContainer config={chartConfig} className="h-[400px] w-full">
            <BarChart data={chartData} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis type="number" tickLine={false} axisLine={false} />
                <YAxis type="category" dataKey="name" width={120} tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent nameKey="name" />} />
                <Bar dataKey="datasets" radius={[0, 4, 4, 0]}>
                    {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                    ))}
                </Bar>
            </BarChart>
        </ChartContainer>
    );
}
