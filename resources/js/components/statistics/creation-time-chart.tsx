import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';

import { type ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';

type CreationTimeChartProps = {
    data: Array<{
        hour: number;
        count: number;
    }>;
};

const chartConfig = {
    count: {
        label: 'Datasets Created',
        color: '#3b82f6',
    },
} satisfies ChartConfig;

export default function CreationTimeChart({ data }: CreationTimeChartProps) {
    // Create array with all 24 hours
    const hourlyData = Array.from({ length: 24 }, (_, index) => {
        const hourData = data.find((h) => h.hour === index);
        return {
            hour: `${index.toString().padStart(2, '0')}:00`,
            count: hourData?.count || 0,
        };
    });

    return (
        <ChartContainer config={chartConfig} className="h-[350px] w-full">
            <LineChart data={hourlyData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="hour" tickLine={false} axisLine={false} angle={-45} textAnchor="end" height={80} />
                <YAxis tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Line
                    type="monotone"
                    dataKey="count"
                    stroke="var(--color-count)"
                    strokeWidth={2}
                    dot={{ fill: 'var(--color-count)', r: 4 }}
                    activeDot={{ r: 6 }}
                />
            </LineChart>
        </ChartContainer>
    );
}
