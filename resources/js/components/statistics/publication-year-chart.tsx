import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { type ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';

type PublicationYearChartProps = {
    data: Array<{
        year: number;
        count: number;
    }>;
};

const chartConfig = {
    count: {
        label: 'Publications',
        color: 'hsl(var(--primary))',
    },
} satisfies ChartConfig;

export default function PublicationYearChart({ data }: PublicationYearChartProps) {
    return (
        <ChartContainer config={chartConfig} className="h-[350px] w-full">
            <AreaChart data={data}>
                <defs>
                    <linearGradient id="colorCount" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="var(--color-count)" stopOpacity={0.8} />
                        <stop offset="95%" stopColor="var(--color-count)" stopOpacity={0.1} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="year" tickLine={false} axisLine={false} domain={['dataMin', 'dataMax']} />
                <YAxis tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Area type="monotone" dataKey="count" stroke="var(--color-count)" fillOpacity={1} fill="url(#colorCount)" />
            </AreaChart>
        </ChartContainer>
    );
}
