import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { settings } from '@/routes';
import { type BreadcrumbItem } from '@/types';

interface ResourceTypeRow {
    id: number;
    name: string;
    active: boolean;
    elmo_active: boolean;
}

interface EditorSettingsProps {
    resourceTypes: ResourceTypeRow[];
    maxTitles: number;
    maxLicenses: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Editor Settings', href: settings().url }];

export default function EditorSettings({ resourceTypes, maxTitles, maxLicenses }: EditorSettingsProps) {
    const { data, setData, post, processing } = useForm({
        resourceTypes: resourceTypes.map((r) => ({
            id: r.id,
            name: r.name,
            active: r.active,
            elmo_active: r.elmo_active,
        })),
        maxTitles,
        maxLicenses,
    });

    const handleTypeChange = (index: number, value: string) => {
        setData(
            'resourceTypes',
            data.resourceTypes.map((r, i) => (i === index ? { ...r, name: value } : r)),
        );
    };

    const handleActiveChange = (index: number, value: boolean) => {
        setData(
            'resourceTypes',
            data.resourceTypes.map((r, i) => (i === index ? { ...r, active: value } : r)),
        );
    };

    const handleElmoActiveChange = (index: number, value: boolean) => {
        setData(
            'resourceTypes',
            data.resourceTypes.map((r, i) => (i === index ? { ...r, elmo_active: value } : r)),
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(settings().url);
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
                                <th className="border-b p-2">Active</th>
                                <th className="border-b p-2">ELMO active</th>
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
                                    <td className="border-b p-2 text-center">
                                        <Label htmlFor={`active-${type.id}`} className="sr-only">
                                            Active
                                        </Label>
                                        <Checkbox
                                            id={`active-${type.id}`}
                                            checked={type.active}
                                            onCheckedChange={(checked) =>
                                                handleActiveChange(index, checked === true)
                                            }
                                        />
                                    </td>
                                    <td className="border-b p-2 text-center">
                                        <Label htmlFor={`elmo-active-${type.id}`} className="sr-only">
                                            ELMO active
                                        </Label>
                                        <Checkbox
                                            id={`elmo-active-${type.id}`}
                                            checked={type.elmo_active}
                                            onCheckedChange={(checked) =>
                                                handleElmoActiveChange(index, checked === true)
                                            }
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
