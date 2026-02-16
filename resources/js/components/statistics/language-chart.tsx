import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, XAxis, YAxis } from 'recharts';

import { type ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';

type LanguageData = {
    language: string;
    count: number;
};

type LanguageChartProps = {
    data: LanguageData[];
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

export default function LanguageChart({ data }: LanguageChartProps) {
    const chartData = data.map((item) => ({
        language: item.language,
        count: item.count,
    }));

    const chartConfig = useMemo(
        () =>
            ({
                count: { label: 'Count' },
                ...Object.fromEntries(chartData.map((item, i) => [item.language, { label: item.language, color: COLORS[i % COLORS.length] }])),
            }) satisfies ChartConfig,
        [chartData],
    );

    return (
        <ChartContainer config={chartConfig} className="h-[300px] w-full">
            <BarChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="language" tickLine={false} axisLine={false} />
                <YAxis tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent nameKey="language" />} />
                <Bar dataKey="count" radius={[4, 4, 0, 0]}>
                    {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                    ))}
                </Bar>
            </BarChart>
        </ChartContainer>
    );
}
