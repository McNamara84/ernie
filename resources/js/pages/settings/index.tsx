import { BentoGrid, BentoGridItem } from '@/components/ui/bento-grid';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { settings } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';

interface ResourceTypeRow {
    id: number;
    name: string;
    active: boolean;
    elmo_active: boolean;
}

interface TitleTypeRow {
    id: number;
    name: string;
    slug: string;
    active: boolean;
    elmo_active: boolean;
}

interface LicenseRow {
    id: number;
    identifier: string;
    name: string;
    active: boolean;
    elmo_active: boolean;
}

interface LanguageRow {
    id: number;
    code: string;
    name: string;
    active: boolean;
    elmo_active: boolean;
}

interface EditorSettingsProps {
    resourceTypes: ResourceTypeRow[];
    titleTypes: TitleTypeRow[];
    licenses: LicenseRow[];
    languages: LanguageRow[];
    maxTitles: number;
    maxLicenses: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Editor Settings', href: settings().url }];

export default function EditorSettings({ resourceTypes, titleTypes, licenses, languages, maxTitles, maxLicenses }: EditorSettingsProps) {
    const { data, setData, post, processing } = useForm({
        resourceTypes: resourceTypes.map((r) => ({
            id: r.id,
            name: r.name,
            active: r.active,
            elmo_active: r.elmo_active,
        })),
        titleTypes: titleTypes.map((t) => ({
            id: t.id,
            name: t.name,
            slug: t.slug,
            active: t.active,
            elmo_active: t.elmo_active,
        })),
        licenses: licenses.map((l) => ({
            id: l.id,
            identifier: l.identifier,
            name: l.name,
            active: l.active,
            elmo_active: l.elmo_active,
        })),
        languages: languages.map((l) => ({
            id: l.id,
            code: l.code,
            name: l.name,
            active: l.active,
            elmo_active: l.elmo_active,
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

    const handleTitleTypeChange = (index: number, field: 'name' | 'slug', value: string) => {
        setData(
            'titleTypes',
            data.titleTypes.map((t, i) => (i === index ? { ...t, [field]: value } : t)),
        );
    };

    const handleTitleActiveChange = (index: number, value: boolean) => {
        setData(
            'titleTypes',
            data.titleTypes.map((t, i) => (i === index ? { ...t, active: value } : t)),
        );
    };

    const handleTitleElmoActiveChange = (index: number, value: boolean) => {
        setData(
            'titleTypes',
            data.titleTypes.map((t, i) => (i === index ? { ...t, elmo_active: value } : t)),
        );
    };

    const handleLicenseActiveChange = (index: number, value: boolean) => {
        setData(
            'licenses',
            data.licenses.map((l, i) => (i === index ? { ...l, active: value } : l)),
        );
    };

    const handleLicenseElmoActiveChange = (index: number, value: boolean) => {
        setData(
            'licenses',
            data.licenses.map((l, i) => (i === index ? { ...l, elmo_active: value } : l)),
        );
    };

    const handleLanguageActiveChange = (index: number, value: boolean) => {
        setData(
            'languages',
            data.languages.map((l, i) => (i === index ? { ...l, active: value } : l)),
        );
    };

    const handleLanguageElmoActiveChange = (index: number, value: boolean) => {
        setData(
            'languages',
            data.languages.map((l, i) => (i === index ? { ...l, elmo_active: value } : l)),
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(settings().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editor Settings" />
            <form onSubmit={handleSubmit} className="p-4 flex flex-col gap-4">
                <Button type="submit" className="self-start" disabled={processing}>
                    Save
                </Button>
                <BentoGrid data-testid="bento-grid">
                    <BentoGridItem aria-labelledby="licenses-heading">
                        <h2 id="licenses-heading" className="text-lg font-semibold">
                            Licenses
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="text-left">
                                    <th className="border-b p-2">ID</th>
                                    <th className="border-b p-2">Identifier</th>
                                    <th className="border-b p-2">Name</th>
                                    <th className="border-b p-2 text-center">
                                        ERNIE
                                        <br />
                                        active
                                    </th>
                                    <th className="border-b p-2 text-center">
                                        ELMO
                                        <br />
                                        active
                                    </th>
                                </tr>
                                </thead>
                                <tbody>
                                    {data.licenses.map((license, index) => (
                                        <tr key={license.id}>
                                            <td className="border-b p-2">{license.id}</td>
                                            <td className="border-b p-2">{license.identifier}</td>
                                            <td className="border-b p-2">{license.name}</td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`lic-active-${license.id}`} className="sr-only">
                                                    ERNIE active
                                                </Label>
                                                <Checkbox
                                                    id={`lic-active-${license.id}`}
                                                    checked={license.active}
                                                    onCheckedChange={(checked) => handleLicenseActiveChange(index, checked === true)}
                                                />
                                            </td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`lic-elmo-active-${license.id}`} className="sr-only">
                                                    ELMO active
                                                </Label>
                                                <Checkbox
                                                    id={`lic-elmo-active-${license.id}`}
                                                    checked={license.elmo_active}
                                                    onCheckedChange={(checked) => handleLicenseElmoActiveChange(index, checked === true)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </BentoGridItem>

                    <div className="grid gap-4 md:col-span-1 lg:col-span-2 md:grid-cols-1 lg:grid-cols-2">
                    <BentoGridItem aria-labelledby="resource-types-heading">
                        <h2 id="resource-types-heading" className="text-lg font-semibold">
                            Resource Types
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="text-left">
                                    <th className="border-b p-2">ID</th>
                                    <th className="border-b p-2">Name</th>
                                    <th className="border-b p-2 text-center">
                                        ERNIE
                                        <br />
                                        active
                                    </th>
                                    <th className="border-b p-2 text-center">
                                        ELMO
                                        <br />
                                        active
                                    </th>
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
                                                    ERNIE active
                                                </Label>
                                                <Checkbox
                                                    id={`active-${type.id}`}
                                                    checked={type.active}
                                                    onCheckedChange={(checked) => handleActiveChange(index, checked === true)}
                                                />
                                            </td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`elmo-active-${type.id}`} className="sr-only">
                                                    ELMO active
                                                </Label>
                                                <Checkbox
                                                    id={`elmo-active-${type.id}`}
                                                    checked={type.elmo_active}
                                                    onCheckedChange={(checked) => handleElmoActiveChange(index, checked === true)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </BentoGridItem>

                    <BentoGridItem aria-labelledby="title-types-heading">
                        <h2 id="title-types-heading" className="text-lg font-semibold">
                            Title Types
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="text-left">
                                    <th className="border-b p-2">ID</th>
                                    <th className="border-b p-2">Name</th>
                                    <th className="border-b p-2">Slug</th>
                                    <th className="border-b p-2 text-center">
                                        ERNIE
                                        <br />
                                        active
                                    </th>
                                    <th className="border-b p-2 text-center">
                                        ELMO
                                        <br />
                                        active
                                    </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.titleTypes.map((type, index) => (
                                        <tr key={type.id}>
                                            <td className="border-b p-2">{type.id}</td>
                                            <td className="border-b p-2">
                                                <Label htmlFor={`tt-name-${type.id}`} className="sr-only">
                                                    Name
                                                </Label>
                                                <Input
                                                    id={`tt-name-${type.id}`}
                                                    value={type.name}
                                                    onChange={(e) => handleTitleTypeChange(index, 'name', e.target.value)}
                                                />
                                            </td>
                                            <td className="border-b p-2">
                                                <Label htmlFor={`tt-slug-${type.id}`} className="sr-only">
                                                    Slug
                                                </Label>
                                                <Input
                                                    id={`tt-slug-${type.id}`}
                                                    value={type.slug}
                                                    onChange={(e) => handleTitleTypeChange(index, 'slug', e.target.value)}
                                                />
                                            </td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`tt-active-${type.id}`} className="sr-only">
                                                    ERNIE active
                                                </Label>
                                                <Checkbox
                                                    id={`tt-active-${type.id}`}
                                                    checked={type.active}
                                                    onCheckedChange={(checked) => handleTitleActiveChange(index, checked === true)}
                                                />
                                            </td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`tt-elmo-active-${type.id}`} className="sr-only">
                                                    ELMO active
                                                </Label>
                                                <Checkbox
                                                    id={`tt-elmo-active-${type.id}`}
                                                    checked={type.elmo_active}
                                                    onCheckedChange={(checked) => handleTitleElmoActiveChange(index, checked === true)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </BentoGridItem>

                    <BentoGridItem aria-labelledby="languages-heading">
                        <h2 id="languages-heading" className="text-lg font-semibold">
                            Languages
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="text-left">
                                    <th className="border-b p-2">ID</th>
                                    <th className="border-b p-2">Code</th>
                                    <th className="border-b p-2">Name</th>
                                    <th className="border-b p-2 text-center">
                                        ERNIE
                                        <br />
                                        active
                                    </th>
                                    <th className="border-b p-2 text-center">
                                        ELMO
                                        <br />
                                        active
                                    </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.languages.map((language, index) => (
                                        <tr key={language.id}>
                                            <td className="border-b p-2">{language.id}</td>
                                            <td className="border-b p-2">{language.code}</td>
                                            <td className="border-b p-2">{language.name}</td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`lang-active-${language.id}`} className="sr-only">
                                                    ERNIE active
                                                </Label>
                                                <Checkbox
                                                    id={`lang-active-${language.id}`}
                                                    checked={language.active}
                                                    onCheckedChange={(checked) => handleLanguageActiveChange(index, checked === true)}
                                                />
                                            </td>
                                            <td className="border-b p-2 text-center">
                                                <Label htmlFor={`lang-elmo-active-${language.id}`} className="sr-only">
                                                    ELMO active
                                                </Label>
                                                <Checkbox
                                                    id={`lang-elmo-active-${language.id}`}
                                                    checked={language.elmo_active}
                                                    onCheckedChange={(checked) => handleLanguageElmoActiveChange(index, checked === true)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </BentoGridItem>

                    <BentoGridItem>
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
                    </BentoGridItem>
                    </div>
                </BentoGrid>
                <Button type="submit" className="self-start" disabled={processing}>
                    Save
                </Button>
            </form>
        </AppLayout>
    );
}
