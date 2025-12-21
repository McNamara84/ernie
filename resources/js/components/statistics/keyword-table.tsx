import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

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
                <CardDescription>Most frequently used controlled keywords ({data.controlled.length} shown)</CardDescription>
            </CardHeader>
            <CardContent>
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
                                    <TableCell>{item.thesaurus && <Badge variant="outline">{item.thesaurus}</Badge>}</TableCell>
                                    <TableCell className="text-right">{item.count.toLocaleString()}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}
