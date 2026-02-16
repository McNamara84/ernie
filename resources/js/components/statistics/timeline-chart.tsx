import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { type ChartConfig, ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';

type TimelineData = {
    publicationsByYear: Array<{
        year: number;
        count: number;
    }>;
    createdByYear: Array<{
        year: number;
        count: number;
    }>;
};

type TimelineChartProps = {
    data: TimelineData;
};

const chartConfig = {
    publications: {
        label: 'Publications by Year',
        color: 'hsl(var(--primary))',
    },
    created: {
        label: 'Datasets Created',
        color: 'hsl(142.1 76.2% 36.3%)',
    },
} satisfies ChartConfig;

export default function TimelineChart({ data }: TimelineChartProps) {
    // Merge both datasets by year
    const yearMap = new Map<number, { year: number; publications: number; created: number }>();

    data.publicationsByYear.forEach((item) => {
        yearMap.set(item.year, { year: item.year, publications: item.count, created: 0 });
    });

    data.createdByYear.forEach((item) => {
        const existing = yearMap.get(item.year);
        if (existing) {
            existing.created = item.count;
        } else {
            yearMap.set(item.year, { year: item.year, publications: 0, created: item.count });
        }
    });

    const chartData = Array.from(yearMap.values()).sort((a, b) => a.year - b.year);

    return (
        <ChartContainer config={chartConfig} className="h-[400px] w-full">
            <AreaChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="year" tickLine={false} axisLine={false} />
                <YAxis tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <ChartLegend content={<ChartLegendContent />} />
                <Area
                    type="monotone"
                    dataKey="publications"
                    stackId="1"
                    stroke="var(--color-publications)"
                    fill="var(--color-publications)"
                />
                <Area
                    type="monotone"
                    dataKey="created"
                    stackId="2"
                    stroke="var(--color-created)"
                    fill="var(--color-created)"
                />
            </AreaChart>
        </ChartContainer>
    );
}
