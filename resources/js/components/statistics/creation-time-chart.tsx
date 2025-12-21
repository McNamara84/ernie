import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type CreationTimeChartProps = {
    data: Array<{
        hour: number;
        count: number;
    }>;
};

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
        <ResponsiveContainer width="100%" height={350}>
            <LineChart data={hourlyData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis dataKey="hour" className="text-xs" tick={{ fill: 'hsl(var(--foreground))' }} angle={-45} textAnchor="end" height={80} />
                <YAxis className="text-xs" tick={{ fill: 'hsl(var(--foreground))' }} />
                <Tooltip
                    contentStyle={{
                        backgroundColor: 'hsl(var(--background))',
                        border: '1px solid hsl(var(--border))',
                        borderRadius: '6px',
                    }}
                />
                <Line
                    type="monotone"
                    dataKey="count"
                    stroke="#3b82f6"
                    strokeWidth={2}
                    dot={{ fill: '#3b82f6', r: 4 }}
                    activeDot={{ r: 6 }}
                    name="Datasets Created"
                />
            </LineChart>
        </ResponsiveContainer>
    );
}
