import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

type StatsCardProps = {
    title: string;
    value: string;
    description?: string;
    icon?: React.ReactNode;
};

export default function StatsCard({ title, value, description, icon }: StatsCardProps) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                {icon}
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
                {description && (
                    <CardDescription className="text-xs">{description}</CardDescription>
                )}
            </CardContent>
        </Card>
    );
}
