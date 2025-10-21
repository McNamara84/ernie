import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type InstitutionData = {
    name: string;
    rorId: string | null;
    count: number;
};

type InstitutionChartProps = {
    data: InstitutionData[];
};

export default function InstitutionChart({ data }: InstitutionChartProps) {
    // Transform data for recharts (needs name and value)
    const chartData = data.map((item) => ({
        name: item.name.length > 40 ? item.name.substring(0, 37) + '...' : item.name,
        fullName: item.name,
        datasets: item.count,
        rorId: item.rorId,
    }));

    return (
        <ResponsiveContainer width="100%" height={400}>
            <BarChart
                data={chartData}
                layout="vertical"
                margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
            >
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis type="number" className="text-xs" />
                <YAxis
                    type="category"
                    dataKey="name"
                    width={200}
                    className="text-xs"
                />
                <Tooltip
                    content={({ active, payload }) => {
                        if (active && payload && payload.length) {
                            const data = payload[0].payload;
                            return (
                                <div className="rounded-lg border bg-background p-2 shadow-sm">
                                    <div className="grid gap-2">
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                Institution
                                            </span>
                                            <span className="font-bold">{data.fullName}</span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                Datasets
                                            </span>
                                            <span className="font-bold text-muted-foreground">
                                                {data.datasets}
                                            </span>
                                        </div>
                                        {data.rorId && (
                                            <div className="flex flex-col">
                                                <span className="text-[0.70rem] uppercase text-muted-foreground">
                                                    ROR ID
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {data.rorId}
                                                </span>
                                            </div>
                                        )}
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
