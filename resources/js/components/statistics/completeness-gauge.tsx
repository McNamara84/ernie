import { Progress } from '@/components/ui/progress';

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

const metrics = [
    { key: 'descriptions', label: 'Descriptions', color: 'bg-blue-500' },
    { key: 'geographicCoverage', label: 'Geographic Coverage', color: 'bg-green-500' },
    { key: 'temporalCoverage', label: 'Temporal Coverage', color: 'bg-yellow-500' },
    { key: 'funding', label: 'Funding References', color: 'bg-purple-500' },
    { key: 'orcid', label: 'ORCID for Authors', color: 'bg-orange-500' },
    { key: 'rorIds', label: 'ROR IDs for Affiliations', color: 'bg-pink-500' },
    { key: 'relatedWorks', label: 'Related Works', color: 'bg-cyan-500' },
] as const;

export default function CompletenessGauge({ data }: CompletenessGaugeProps) {
    return (
        <div className="space-y-4">
            {metrics.map((metric) => {
                const value = data[metric.key];
                return (
                    <div key={metric.key} className="space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium">{metric.label}</span>
                            <span className="text-sm font-bold">{value.toFixed(2)}%</span>
                        </div>
                        <Progress value={value} className="h-2" />
                    </div>
                );
            })}
        </div>
    );
}
