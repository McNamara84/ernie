import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

type DescriptionTypeStatsProps = {
    data: Array<{
        type_id: string;
        count: number;
    }>;
};

export default function DescriptionTypeStats({ data }: DescriptionTypeStatsProps) {
    return (
        <div className="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Description Type</TableHead>
                        <TableHead className="text-right">Count</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {data.map((item, index) => (
                        <TableRow key={index}>
                            <TableCell>{item.type_id}</TableCell>
                            <TableCell className="text-right">{item.count.toLocaleString()}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
