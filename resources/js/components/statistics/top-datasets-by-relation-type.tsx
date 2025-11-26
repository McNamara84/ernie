import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

type DatasetEntry = {
    id: number;
    identifier: string;
    title: string | null;
    count: number;
};

type TopDatasetsByRelationTypeData = {
    [relationType: string]: DatasetEntry[];
};

type TopDatasetsByRelationTypeProps = {
    data: TopDatasetsByRelationTypeData;
};

// Map relation types to descriptive labels and emojis
const relationTypeInfo: Record<string, { label: string; emoji: string; description: string }> = {
    Cites: {
        label: 'Cites',
        emoji: '📖',
        description: 'Datasets that cite other works most frequently',
    },
    References: {
        label: 'References',
        emoji: '📚',
        description: 'Datasets that reference other works most frequently',
    },
    IsSupplementTo: {
        label: 'IsSupplementTo',
        emoji: '📎',
        description: 'Datasets that are supplements to publications most frequently',
    },
    IsCitedBy: {
        label: 'IsCitedBy',
        emoji: '🏆',
        description: 'Datasets that are cited by other works most frequently',
    },
    IsReferencedBy: {
        label: 'IsReferencedBy',
        emoji: '🔗',
        description: 'Datasets that are referenced by other works most frequently',
    },
    IsNewVersionOf: {
        label: 'IsNewVersionOf',
        emoji: '🆕',
        description: 'Datasets that are new versions of other datasets most frequently',
    },
    IsPreviousVersionOf: {
        label: 'IsPreviousVersionOf',
        emoji: '⏮️',
        description: 'Datasets that are previous versions of other datasets most frequently',
    },
    IsPartOf: {
        label: 'IsPartOf',
        emoji: '🧩',
        description: 'Datasets that are parts of larger collections most frequently',
    },
    HasPart: {
        label: 'HasPart',
        emoji: '📦',
        description: 'Datasets that contain multiple parts most frequently',
    },
    IsVariantFormOf: {
        label: 'IsVariantFormOf',
        emoji: '🔄',
        description: 'Datasets that are variant forms of other datasets most frequently',
    },
};

// Ordered list of relation types (by frequency)
const orderedRelationTypes = [
    'Cites',
    'References',
    'IsSupplementTo',
    'IsCitedBy',
    'IsReferencedBy',
    'IsNewVersionOf',
    'IsPreviousVersionOf',
    'IsPartOf',
    'HasPart',
    'IsVariantFormOf',
];

export default function TopDatasetsByRelationType({ data }: TopDatasetsByRelationTypeProps) {
    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-2xl font-bold tracking-tight">
                    🏅 Top 5 Datasets by Relation Type
                </h2>
                <p className="text-muted-foreground">
                    Datasets with the highest usage of each relation type in the legacy database
                </p>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                {orderedRelationTypes.map((relationType) => {
                    const datasets = data[relationType] || [];
                    const info = relationTypeInfo[relationType] || {
                        label: relationType,
                        emoji: '📊',
                        description: `Top datasets using ${relationType}`,
                    };

                    return (
                        <Card key={relationType}>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">
                                    {info.emoji} Top 5: {info.label}
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    {info.description}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {datasets.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No datasets found with this relation type.
                                    </p>
                                ) : (
                                    <div className="max-h-[300px] overflow-y-auto rounded-md border">
                                        <table className="w-full text-sm">
                                            <thead className="sticky top-0 bg-muted">
                                                <tr>
                                                    <th className="p-2 text-left font-medium">#</th>
                                                    <th className="p-2 text-left font-medium">
                                                        Identifier
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        Count
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {datasets.map((dataset, index) => (
                                                    <tr
                                                        key={dataset.id}
                                                        className="border-t hover:bg-muted/50"
                                                        title={dataset.title || undefined}
                                                    >
                                                        <td className="p-2 text-muted-foreground">
                                                            {index + 1}
                                                        </td>
                                                        <td className="p-2">
                                                            <div className="flex flex-col">
                                                                <span className="font-mono text-xs">
                                                                    {dataset.identifier}
                                                                </span>
                                                                {dataset.title && (
                                                                    <span className="max-w-[200px] truncate text-xs text-muted-foreground">
                                                                        {dataset.title}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="p-2 text-right font-bold">
                                                            {dataset.count}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </div>
    );
}
