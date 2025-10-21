type AffiliationStatsCardProps = {
    data: {
        max_per_agent: number;
        avg_per_agent: number;
    };
};

export default function AffiliationStatsCard({ data }: AffiliationStatsCardProps) {
    return (
        <div className="grid gap-6 md:grid-cols-2">
            <div className="rounded-lg border bg-card p-6 text-center">
                <div className="text-5xl font-bold text-primary">{data.max_per_agent}</div>
                <h3 className="mt-2 font-semibold">Maximum Affiliations</h3>
                <p className="text-sm text-muted-foreground">
                    Highest number of affiliations per author/contributor
                </p>
            </div>

            <div className="rounded-lg border bg-card p-6 text-center">
                <div className="text-5xl font-bold text-primary">{data.avg_per_agent}</div>
                <h3 className="mt-2 font-semibold">Average Affiliations</h3>
                <p className="text-sm text-muted-foreground">
                    Average affiliations per author/contributor
                </p>
            </div>
        </div>
    );
}
