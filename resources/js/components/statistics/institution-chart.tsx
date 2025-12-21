import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type InstitutionData = {
    name: string;
    rorId: string | null;
    count: number;
};

type InstitutionChartProps = {
    data: InstitutionData[];
};

const COLORS = [
    'hsl(221.2 83.2% 53.3%)', // Blue
    'hsl(142.1 76.2% 36.3%)', // Green
    'hsl(24.6 95% 53.1%)', // Orange
    'hsl(262.1 83.3% 57.8%)', // Purple
    'hsl(346.8 77.2% 49.8%)', // Red
    'hsl(173 58% 39%)', // Teal
    'hsl(43 74% 66%)', // Yellow
    'hsl(280 65% 60%)', // Violet
    'hsl(12 76% 61%)', // Coral
    'hsl(198 70% 50%)', // Sky
    'hsl(48 96% 53%)', // Amber
    'hsl(339 90% 51%)', // Pink
    'hsl(162 63% 41%)', // Emerald
    'hsl(258 90% 66%)', // Indigo
    'hsl(27 87% 67%)', // Peach
];

export default function InstitutionChart({ data }: InstitutionChartProps) {
    // Transform data for recharts (needs name and value)
    const chartData = data.map((item, index) => ({
        name: item.name.length > 40 ? item.name.substring(0, 37) + '...' : item.name,
        fullName: item.name,
        datasets: item.count,
        rorId: item.rorId,
        color: COLORS[index % COLORS.length],
    }));

    return (
        <ResponsiveContainer width="100%" height={400}>
            <BarChart data={chartData} layout="vertical" margin={{ top: 5, right: 30, left: 20, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis type="number" className="text-xs" />
                <YAxis type="category" dataKey="name" width={200} className="text-xs" />
                <Tooltip
                    content={({ active, payload }) => {
                        if (active && payload && payload.length) {
                            const data = payload[0].payload;
                            return (
                                <div className="rounded-lg border bg-background p-2 shadow-sm">
                                    <div className="grid gap-2">
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Institution</span>
                                            <span className="font-bold">{data.fullName}</span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Datasets</span>
                                            <span className="font-bold text-muted-foreground">{data.datasets}</span>
                                        </div>
                                        {data.rorId && (
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] text-muted-foreground uppercase">ROR ID</span>
                                                <span className="text-xs text-muted-foreground">{data.rorId}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        }
                        return null;
                    }}
                />
                <Bar dataKey="datasets" radius={[0, 4, 4, 0]}>
                    {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.color} />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
