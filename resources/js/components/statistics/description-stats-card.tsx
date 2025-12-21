import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type DescriptionStatsCardProps = {
    data: {
        by_type: Array<{
            type_id: string; // Changed from number to string
            count: number;
        }>;
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

export default function DescriptionStatsCard({ data }: DescriptionStatsCardProps) {
    return (
        <Tabs defaultValue="types" className="w-full">
            <TabsList className="grid w-full grid-cols-2">
                <TabsTrigger value="types">By Type</TabsTrigger>
                <TabsTrigger value="abstracts">Abstract Analysis</TabsTrigger>
            </TabsList>

            <TabsContent value="types" className="mt-4">
                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Description Type</TableHead>
                                <TableHead className="text-right">Count</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.by_type.map((item, index) => (
                                <TableRow key={index}>
                                    <TableCell>{item.type_id}</TableCell>
                                    <TableCell className="text-right">{item.count.toLocaleString()}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </TabsContent>

            <TabsContent value="abstracts" className="mt-4 space-y-4">
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
            </TabsContent>
        </Tabs>
    );
}
