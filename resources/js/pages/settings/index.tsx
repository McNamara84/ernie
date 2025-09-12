import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';

interface ResourceTypeRow {
    id: number;
    name: string;
}

interface EditorSettingsProps {
    resourceTypes: ResourceTypeRow[];
    maxTitles: number;
    maxLicenses: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Editor Settings', href: '/settings' }];

export default function EditorSettings({ resourceTypes, maxTitles, maxLicenses }: EditorSettingsProps) {
    const { data, setData, post, processing } = useForm({
        resourceTypes: resourceTypes.map((r) => ({ id: r.id, name: r.name })),
        maxTitles,
        maxLicenses,
    });

    const handleTypeChange = (index: number, value: string) => {
        setData('resourceTypes', data.resourceTypes.map((r, i) => (i === index ? { ...r, name: value } : r)));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/settings');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editor Settings" />
            <form onSubmit={handleSubmit} className="space-y-6 p-4">
                <div>
                    <h2 className="mb-4 text-lg font-semibold">Resource Types</h2>
                    <table className="w-full border-collapse">
                        <thead>
                            <tr className="text-left">
                                <th className="border-b p-2">ID</th>
                                <th className="border-b p-2">Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.resourceTypes.map((type, index) => (
                                <tr key={type.id}>
                                    <td className="border-b p-2">{type.id}</td>
                                    <td className="border-b p-2">
                                        <Label htmlFor={`rt-${type.id}`} className="sr-only">
                                            Name
                                        </Label>
                                        <Input
                                            id={`rt-${type.id}`}
                                            value={type.name}
                                            onChange={(e) => handleTypeChange(index, e.target.value)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="maxTitles">Max Titles</Label>
                        <Input
                            id="maxTitles"
                            type="number"
                            min={1}
                            value={data.maxTitles}
                            onChange={(e) => setData('maxTitles', Number(e.target.value))}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="maxLicenses">Max Licenses</Label>
                        <Input
                            id="maxLicenses"
                            type="number"
                            min={1}
                            value={data.maxLicenses}
                            onChange={(e) => setData('maxLicenses', Number(e.target.value))}
                        />
                    </div>
                </div>

                <Button type="submit" disabled={processing}>
                    Save
                </Button>
            </form>
        </AppLayout>
    );
}
