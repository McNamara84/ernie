import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';

type KeywordTableProps = {
    data: {
        free: Array<{
            keyword: string;
            count: number;
        }>;
        controlled: Array<{
            keyword: string;
            count: number;
            thesaurus?: string;
        }>;
        by_thesaurus?: Array<{
            thesaurus: string;
            keyword_count: number;
            dataset_count: number;
        }>;
    };
};

export default function KeywordTable({ data }: KeywordTableProps) {
    return (
        <Card className="col-span-2">
            <CardHeader>
                <CardTitle>Top Keywords</CardTitle>
                <CardDescription>
                    Most frequently used keywords (controlled only - all keywords have a thesaurus)
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Tabs defaultValue="controlled" className="w-full">
                    <TabsList className="grid w-full grid-cols-2">
                        <TabsTrigger value="controlled">
                            Controlled Keywords ({data.controlled.length})
                        </TabsTrigger>
                        <TabsTrigger value="thesaurus">
                            By Thesaurus
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="controlled" className="mt-4">
                        <div className="max-h-[400px] overflow-auto rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">#</TableHead>
                                        <TableHead>Keyword</TableHead>
                                        <TableHead>Thesaurus</TableHead>
                                        <TableHead className="text-right">Usage Count</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.controlled.map((item, index) => (
                                        <TableRow key={index}>
                                            <TableCell className="font-medium">{index + 1}</TableCell>
                                            <TableCell>{item.keyword}</TableCell>
                                            <TableCell>
                                                {item.thesaurus && (
                                                    <Badge variant="outline">{item.thesaurus}</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {item.count.toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </TabsContent>

                    <TabsContent value="thesaurus" className="mt-4">
                        <div className="max-h-[400px] overflow-auto rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Thesaurus</TableHead>
                                        <TableHead className="text-right">Unique Keywords</TableHead>
                                        <TableHead className="text-right">Datasets</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.by_thesaurus?.map((item, index) => (
                                        <TableRow key={index}>
                                            <TableCell>
                                                <Badge variant="secondary">{item.thesaurus}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {item.keyword_count.toLocaleString()}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {item.dataset_count.toLocaleString()}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </TabsContent>
                </Tabs>
            </CardContent>
        </Card>
    );
}
