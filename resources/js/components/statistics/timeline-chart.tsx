import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

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
        <ResponsiveContainer width="100%" height={400}>
            <AreaChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis dataKey="year" className="text-xs" />
                <YAxis className="text-xs" />
                <Tooltip
                    content={({ active, payload }) => {
                        if (active && payload && payload.length) {
                            return (
                                <div className="rounded-lg border bg-background p-2 shadow-sm">
                                    <div className="grid gap-2">
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Year</span>
                                            <span className="font-bold">{payload[0].payload.year}</span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Publications</span>
                                            <span className="font-bold text-blue-500">{payload[0].payload.publications}</span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="text-[0.70rem] text-muted-foreground uppercase">Datasets Created</span>
                                            <span className="font-bold text-green-500">{payload[0].payload.created}</span>
                                        </div>
                                    </div>
                                </div>
                            );
                        }
                        return null;
                    }}
                />
                <Legend />
                <Area
                    type="monotone"
                    dataKey="publications"
                    stackId="1"
                    stroke="hsl(var(--primary))"
                    fill="hsl(var(--primary))"
                    name="Publications by Year"
                />
                <Area
                    type="monotone"
                    dataKey="created"
                    stackId="2"
                    stroke="hsl(142.1 76.2% 36.3%)"
                    fill="hsl(142.1 76.2% 36.3%)"
                    name="Datasets Created"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
