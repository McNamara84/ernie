type AbstractAnalysisProps = {
    data: {
        longest_abstract: {
            length: number;
            preview: string;
        } | null;
        shortest_abstract: {
            length: number;
            preview: string;
        } | null;
    };
};

export default function AbstractAnalysis({ data }: AbstractAnalysisProps) {
    return (
        <div className="space-y-4">
            {data.longest_abstract && (
                <div className="rounded-lg border bg-card p-4">
                    <div className="mb-2 flex items-center justify-between">
                        <h4 className="font-semibold">Longest Abstract</h4>
                        <span className="text-sm text-muted-foreground">{data.longest_abstract.length.toLocaleString()} characters</span>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {data.longest_abstract.preview}
                        {data.longest_abstract.length > 200 && '...'}
                    </p>
                </div>
            )}

            {data.shortest_abstract && (
                <div className="rounded-lg border bg-card p-4">
                    <div className="mb-2 flex items-center justify-between">
                        <h4 className="font-semibold">Shortest Abstract</h4>
                        <span className="text-sm text-muted-foreground">{data.shortest_abstract.length.toLocaleString()} characters</span>
                    </div>
                    <p className="text-sm text-muted-foreground">{data.shortest_abstract.preview}</p>
                </div>
            )}
        </div>
    );
}
