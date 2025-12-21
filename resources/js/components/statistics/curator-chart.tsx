import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

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

    return (
        <ResponsiveContainer width="100%" height={400}>
            <BarChart data={chartData} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis type="number" className="text-xs" />
                <YAxis type="category" dataKey="name" width={120} className="text-xs" />
                <Tooltip
                    content={({ active, payload }) => {
                        if (active && payload && payload.length) {
                            return (
                                <div className="rounded-lg border bg-background p-2 shadow-sm">
                                    <div className="grid gap-2">
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Curator</span>
                                            <span className="font-bold">{payload[0].payload.name}</span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Datasets Curated</span>
                                            <span className="font-bold text-muted-foreground">{payload[0].payload.datasets}</span>
                                        </div>
                                    </div>
                                </div>
                            );
                        }
                        return null;
                    }}
                />
                <Bar dataKey="datasets" radius={[0, 4, 4, 0]}>
                    {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
