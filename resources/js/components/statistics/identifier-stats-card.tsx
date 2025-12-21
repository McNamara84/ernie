type IdentifierStatsProps = {
    data: {
        ror: {
            count: number;
            total: number;
            percentage: number;
        };
        orcid: {
            count: number;
            total: number;
            percentage: number;
        };
    };
};

export default function IdentifierStatsCard({ data }: IdentifierStatsProps) {
    return (
        <div className="space-y-6">
            {/* ROR Statistics */}
            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <h3 className="font-semibold">ROR-IDs in Affiliations</h3>
                        <p className="text-sm text-muted-foreground">
                            {data.ror.count.toLocaleString()} of {data.ror.total.toLocaleString()} affiliations have ROR identifiers
                        </p>
                    </div>
                    <div className="text-right">
                        <div className="text-2xl font-bold">{data.ror.percentage}%</div>
                    </div>
                </div>
                <div className="h-3 w-full overflow-hidden rounded-full bg-secondary">
                    <div
                        className="h-full transition-all"
                        style={{
                            width: `${data.ror.percentage}%`,
                            backgroundColor: '#3b82f6', // blue-500
                        }}
                    />
                </div>
            </div>

            {/* ORCID Statistics */}
            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <h3 className="font-semibold">ORCIDs in Authors/Contributors</h3>
                        <p className="text-sm text-muted-foreground">
                            {data.orcid.count.toLocaleString()} of {data.orcid.total.toLocaleString()} authors/contributors have ORCID identifiers
                        </p>
                    </div>
                    <div className="text-right">
                        <div className="text-2xl font-bold">{data.orcid.percentage}%</div>
                    </div>
                </div>
                <div className="h-3 w-full overflow-hidden rounded-full bg-secondary">
                    <div
                        className="h-full transition-all"
                        style={{
                            width: `${data.orcid.percentage}%`,
                            backgroundColor: '#10b981', // emerald-500
                        }}
                    />
                </div>
            </div>
        </div>
    );
}
