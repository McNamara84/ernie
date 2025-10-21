import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type CuratorData = {
    name: string;
    count: number;
};

type CuratorChartProps = {
    data: CuratorData[];
};

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
                                            <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                Curator
                                            </span>
                                            <span className="font-bold">{payload[0].payload.name}</span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                Datasets Curated
                                            </span>
                                            <span className="font-bold text-muted-foreground">
                                                {payload[0].payload.datasets}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            );
                        }
                        return null;
                    }}
                />
                <Bar dataKey="datasets" fill="hsl(var(--primary))" radius={[0, 4, 4, 0]} />
            </BarChart>
        </ResponsiveContainer>
    );
}
