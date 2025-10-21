import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type CurrentYearChartProps = {
    data: {
        year: number;
        total: number;
        monthly: Array<{
            month: number;
            count: number;
        }>;
    };
};

const MONTH_NAMES = [
    'Jan',
    'Feb',
    'Mär',
    'Apr',
    'Mai',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Okt',
    'Nov',
    'Dez',
];

export default function CurrentYearChart({ data }: CurrentYearChartProps) {
    // Create array with all 12 months
    const monthlyData = MONTH_NAMES.map((name, index) => {
        const monthData = data.monthly.find((m) => m.month === index + 1);
        return {
            month: name,
            count: monthData?.count || 0,
        };
    });

    return (
        <div className="space-y-4">
            <div className="text-center">
                <div className="text-4xl font-bold text-primary">{data.total.toLocaleString()}</div>
                <p className="text-sm text-muted-foreground">
                    Total publications in {data.year}
                </p>
            </div>

            <ResponsiveContainer width="100%" height={300}>
                <BarChart data={monthlyData}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis
                        dataKey="month"
                        className="text-xs"
                        tick={{ fill: 'hsl(var(--foreground))' }}
                    />
                    <YAxis className="text-xs" tick={{ fill: 'hsl(var(--foreground))' }} />
                    <Tooltip
                        contentStyle={{
                            backgroundColor: 'hsl(var(--background))',
                            border: '1px solid hsl(var(--border))',
                            borderRadius: '6px',
                        }}
                    />
                    <Legend />
                    <Bar dataKey="count" fill="hsl(var(--primary))" name="Publications" />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
