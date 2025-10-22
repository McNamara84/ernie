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

export default function CurrentYearChart({ data }: CurrentYearChartProps) {
    // Since we only have year (not date), no monthly breakdown is available
    return (
        <div className="flex h-full items-center justify-center py-12">
            <div className="text-center">
                <div className="text-6xl font-bold text-primary">{data.total.toLocaleString()}</div>
                <p className="mt-4 text-lg text-muted-foreground">
                    Total publications in {data.year}
                </p>
                <p className="mt-2 text-sm text-muted-foreground">
                    (Monthly breakdown not available - only publication year is stored)
                </p>
            </div>
        </div>
    );
}
