import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type PublicationYearChartProps = {
    data: Array<{
        year: number;
        count: number;
    }>;
};

export default function PublicationYearChart({ data }: PublicationYearChartProps) {
    return (
        <ResponsiveContainer width="100%" height={350}>
            <AreaChart data={data}>
                <defs>
                    <linearGradient id="colorCount" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="hsl(var(--primary))" stopOpacity={0.8} />
                        <stop offset="95%" stopColor="hsl(var(--primary))" stopOpacity={0.1} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis
                    dataKey="year"
                    className="text-xs"
                    tick={{ fill: 'hsl(var(--foreground))' }}
                    domain={['dataMin', 'dataMax']}
                />
                <YAxis className="text-xs" tick={{ fill: 'hsl(var(--foreground))' }} />
                <Tooltip
                    contentStyle={{
                        backgroundColor: 'hsl(var(--background))',
                        border: '1px solid hsl(var(--border))',
                        borderRadius: '6px',
                    }}
                />
                <Area
                    type="monotone"
                    dataKey="count"
                    stroke="hsl(var(--primary))"
                    fillOpacity={1}
                    fill="url(#colorCount)"
                    name="Publications"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
