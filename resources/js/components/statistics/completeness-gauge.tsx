type CompletenessData = {
    descriptions: number;
    geographicCoverage: number;
    temporalCoverage: number;
    funding: number;
    orcid: number;
    rorIds: number;
    relatedWorks: number;
};

type CompletenessGaugeProps = {
    data: CompletenessData;
};

const COLORS = [
    '#3b82f6', // blue-500
    '#10b981', // emerald-500
    '#f59e0b', // amber-500
    '#ef4444', // red-500
    '#8b5cf6', // violet-500
    '#ec4899', // pink-500
    '#14b8a6', // teal-500
];

const metrics = [
    { key: 'descriptions', label: 'Descriptions', color: COLORS[0] },
    { key: 'geographicCoverage', label: 'Geographic Coverage', color: COLORS[1] },
    { key: 'temporalCoverage', label: 'Temporal Coverage', color: COLORS[2] },
    { key: 'funding', label: 'Funding References', color: COLORS[3] },
    { key: 'orcid', label: 'ORCID for Authors', color: COLORS[4] },
    { key: 'rorIds', label: 'ROR IDs for Affiliations', color: COLORS[5] },
    { key: 'relatedWorks', label: 'Related Works', color: COLORS[6] },
] as const;

export default function CompletenessGauge({ data }: CompletenessGaugeProps) {
    // Sort metrics by value (descending)
    const sortedMetrics = [...metrics].sort((a, b) => data[b.key] - data[a.key]);

    return (
        <div className="space-y-4">
            {sortedMetrics.map((metric) => {
                const value = data[metric.key];
                return (
                    <div key={metric.key} className="space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium">{metric.label}</span>
                            <span className="text-sm font-bold">{value.toFixed(2)}%</span>
                        </div>
                        <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
                            <div
                                className="h-full transition-all"
                                style={{
                                    width: `${value}%`,
                                    backgroundColor: metric.color,
                                }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
