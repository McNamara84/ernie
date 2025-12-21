import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type KeywordTableProps = {
    data: {
        free: Array<{
            keyword: string;
            count: number;
        }>;
        controlled: Array<{
            keyword: string;
            count: number;
        }>;
    };
};

export default function KeywordTable({ data }: KeywordTableProps) {
    return (
        <Tabs defaultValue="free" className="w-full">
            <TabsList className="grid w-full grid-cols-2">
                <TabsTrigger value="free">Free Keywords ({data.free.length})</TabsTrigger>
                <TabsTrigger value="controlled">Controlled Keywords ({data.controlled.length})</TabsTrigger>
            </TabsList>

            <TabsContent value="free" className="mt-4">
                <div className="max-h-[400px] overflow-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12">#</TableHead>
                                <TableHead>Keyword</TableHead>
                                <TableHead className="text-right">Usage Count</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.free.map((item, index) => (
                                <TableRow key={index}>
                                    <TableCell className="font-medium">{index + 1}</TableCell>
                                    <TableCell>{item.keyword}</TableCell>
                                    <TableCell className="text-right">{item.count.toLocaleString()}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </TabsContent>

            <TabsContent value="controlled" className="mt-4">
                <div className="max-h-[400px] overflow-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12">#</TableHead>
                                <TableHead>Keyword</TableHead>
                                <TableHead className="text-right">Usage Count</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.controlled.map((item, index) => (
                                <TableRow key={index}>
                                    <TableCell className="font-medium">{index + 1}</TableCell>
                                    <TableCell>{item.keyword}</TableCell>
                                    <TableCell className="text-right">{item.count.toLocaleString()}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </TabsContent>
        </Tabs>
    );
}
