import { Head, useForm } from '@inertiajs/react';

import { LicenseResourceTypePopover } from '@/components/settings/license-resource-type-popover';
import { ThesaurusCard, type ThesaurusData } from '@/components/settings/thesaurus-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { settings } from '@/routes';
import { type BreadcrumbItem } from '@/types';

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
    excluded_resource_type_ids: number[];
}

interface LanguageRow {
    id: number;
    code: string;
    name: string;
    active: boolean;
    elmo_active: boolean;
}

interface DateTypeRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    active: boolean;
    elmo_active: boolean;
}

interface EditorSettingsProps {
    resourceTypes: ResourceTypeRow[];
    titleTypes: TitleTypeRow[];
    licenses: LicenseRow[];
    languages: LanguageRow[];
    dateTypes: DateTypeRow[];
    maxTitles: number;
    maxLicenses: number;
    thesauri: ThesaurusData[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Editor Settings', href: settings().url }];

export default function EditorSettings({
    resourceTypes,
    titleTypes,
    licenses,
    languages,
    dateTypes,
    maxTitles,
    maxLicenses,
    thesauri,
}: EditorSettingsProps) {
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
            excluded_resource_type_ids: l.excluded_resource_type_ids,
        })),
        languages: languages.map((l) => ({
            id: l.id,
            code: l.code,
            name: l.name,
            active: l.active,
            elmo_active: l.elmo_active,
        })),
        dateTypes: dateTypes.map((d) => ({
            id: d.id,
            name: d.name,
            slug: d.slug,
            description: d.description,
            active: d.active,
            elmo_active: d.elmo_active,
        })),
        maxTitles,
        maxLicenses,
        thesauri: thesauri.map((t) => ({
            type: t.type,
            isActive: t.isActive,
            isElmoActive: t.isElmoActive,
        })),
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

    const handleLicenseExcludedResourceTypesChange = (index: number, excludedIds: number[]) => {
        setData(
            'licenses',
            data.licenses.map((l, i) => (i === index ? { ...l, excluded_resource_type_ids: excludedIds } : l)),
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

    const handleDateTypeActiveChange = (index: number, value: boolean) => {
        setData(
            'dateTypes',
            data.dateTypes.map((d, i) => (i === index ? { ...d, active: value } : d)),
        );
    };

    const handleDateTypeElmoActiveChange = (index: number, value: boolean) => {
        setData(
            'dateTypes',
            data.dateTypes.map((d, i) => (i === index ? { ...d, elmo_active: value } : d)),
        );
    };

    const handleThesaurusActiveChange = (type: string, isActive: boolean) => {
        setData(
            'thesauri',
            data.thesauri.map((t) => (t.type === type ? { ...t, isActive } : t)),
        );
    };

    const handleThesaurusElmoActiveChange = (type: string, isElmoActive: boolean) => {
        setData(
            'thesauri',
            data.thesauri.map((t) => (t.type === type ? { ...t, isElmoActive } : t)),
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(settings().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editor Settings" />
            <form onSubmit={handleSubmit} className="flex flex-col gap-4 p-4">
                <Button type="submit" className="self-start" disabled={processing}>
                    Save
                </Button>

                <div className="grid items-start gap-4 md:grid-cols-2" data-testid="settings-grid">
                    {/* Left Column - Licenses only */}
                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Licenses</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>ID</TableHead>
                                                <TableHead>Identifier</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead className="text-center">
                                                    ERNIE
                                                    <br />
                                                    active
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    Resource
                                                    <br />
                                                    Types
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.licenses.map((license, index) => (
                                                <TableRow key={license.id}>
                                                    <TableCell>{license.id}</TableCell>
                                                    <TableCell>{license.identifier}</TableCell>
                                                    <TableCell>{license.name}</TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`lic-active-${license.id}`} className="sr-only">
                                                            ERNIE active
                                                        </Label>
                                                        <Checkbox
                                                            id={`lic-active-${license.id}`}
                                                            checked={license.active}
                                                            onCheckedChange={(checked) => handleLicenseActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`lic-elmo-active-${license.id}`} className="sr-only">
                                                            ELMO active
                                                        </Label>
                                                        <Checkbox
                                                            id={`lic-elmo-active-${license.id}`}
                                                            checked={license.elmo_active}
                                                            onCheckedChange={(checked) => handleLicenseElmoActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <LicenseResourceTypePopover
                                                            licenseId={license.id}
                                                            licenseName={license.name}
                                                            resourceTypes={data.resourceTypes.map((rt) => ({
                                                                id: rt.id,
                                                                name: rt.name,
                                                            }))}
                                                            excludedIds={license.excluded_resource_type_ids}
                                                            onExcludedChange={(ids) => handleLicenseExcludedResourceTypesChange(index, ids)}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right Column - All other cards */}
                    <div className="flex flex-col gap-4">
                        {/* Resource Types */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Resource Types</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>ID</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead className="text-center">
                                                    ERNIE
                                                    <br />
                                                    active
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.resourceTypes.map((type, index) => (
                                                <TableRow key={type.id}>
                                                    <TableCell>{type.id}</TableCell>
                                                    <TableCell>
                                                        <Label htmlFor={`rt-${type.id}`} className="sr-only">
                                                            Name
                                                        </Label>
                                                        <Input
                                                            id={`rt-${type.id}`}
                                                            value={type.name}
                                                            onChange={(e) => handleTypeChange(index, e.target.value)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`active-${type.id}`} className="sr-only">
                                                            ERNIE active
                                                        </Label>
                                                        <Checkbox
                                                            id={`active-${type.id}`}
                                                            checked={type.active}
                                                            onCheckedChange={(checked) => handleActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`elmo-active-${type.id}`} className="sr-only">
                                                            ELMO active
                                                        </Label>
                                                        <Checkbox
                                                            id={`elmo-active-${type.id}`}
                                                            checked={type.elmo_active}
                                                            onCheckedChange={(checked) => handleElmoActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Title Types */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Title Types</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>ID</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Slug</TableHead>
                                                <TableHead className="text-center">
                                                    ERNIE
                                                    <br />
                                                    active
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.titleTypes.map((type, index) => (
                                                <TableRow key={type.id}>
                                                    <TableCell>{type.id}</TableCell>
                                                    <TableCell>
                                                        <Label htmlFor={`tt-name-${type.id}`} className="sr-only">
                                                            Name
                                                        </Label>
                                                        <Input
                                                            id={`tt-name-${type.id}`}
                                                            value={type.name}
                                                            onChange={(e) => handleTitleTypeChange(index, 'name', e.target.value)}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <Label htmlFor={`tt-slug-${type.id}`} className="sr-only">
                                                            Slug
                                                        </Label>
                                                        <Input
                                                            id={`tt-slug-${type.id}`}
                                                            value={type.slug}
                                                            onChange={(e) => handleTitleTypeChange(index, 'slug', e.target.value)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`tt-active-${type.id}`} className="sr-only">
                                                            ERNIE active
                                                        </Label>
                                                        <Checkbox
                                                            id={`tt-active-${type.id}`}
                                                            checked={type.active}
                                                            onCheckedChange={(checked) => handleTitleActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`tt-elmo-active-${type.id}`} className="sr-only">
                                                            ELMO active
                                                        </Label>
                                                        <Checkbox
                                                            id={`tt-elmo-active-${type.id}`}
                                                            checked={type.elmo_active}
                                                            onCheckedChange={(checked) => handleTitleElmoActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Languages */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Languages</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>ID</TableHead>
                                                <TableHead>Code</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead className="text-center">
                                                    ERNIE
                                                    <br />
                                                    active
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.languages.map((language, index) => (
                                                <TableRow key={language.id}>
                                                    <TableCell>{language.id}</TableCell>
                                                    <TableCell>{language.code}</TableCell>
                                                    <TableCell>{language.name}</TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`lang-active-${language.id}`} className="sr-only">
                                                            ERNIE active
                                                        </Label>
                                                        <Checkbox
                                                            id={`lang-active-${language.id}`}
                                                            checked={language.active}
                                                            onCheckedChange={(checked) => handleLanguageActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`lang-elmo-active-${language.id}`} className="sr-only">
                                                            ELMO active
                                                        </Label>
                                                        <Checkbox
                                                            id={`lang-elmo-active-${language.id}`}
                                                            checked={language.elmo_active}
                                                            onCheckedChange={(checked) => handleLanguageElmoActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Date Types */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Date Types</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>ID</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Slug</TableHead>
                                                <TableHead className="text-center">
                                                    ERNIE
                                                    <br />
                                                    active
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.dateTypes.map((dateType, index) => (
                                                <TableRow key={dateType.id}>
                                                    <TableCell>{dateType.id}</TableCell>
                                                    <TableCell>{dateType.name}</TableCell>
                                                    <TableCell>{dateType.slug}</TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`dt-active-${dateType.id}`} className="sr-only">
                                                            ERNIE active
                                                        </Label>
                                                        <Checkbox
                                                            id={`dt-active-${dateType.id}`}
                                                            checked={dateType.active}
                                                            onCheckedChange={(checked) => handleDateTypeActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`dt-elmo-active-${dateType.id}`} className="sr-only">
                                                            ELMO active
                                                        </Label>
                                                        <Checkbox
                                                            id={`dt-elmo-active-${dateType.id}`}
                                                            checked={dateType.elmo_active}
                                                            onCheckedChange={(checked) => handleDateTypeElmoActiveChange(index, checked === true)}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Limits */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Limits</CardTitle>
                            </CardHeader>
                            <CardContent>
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
                            </CardContent>
                        </Card>

                        {/* Thesauri */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Thesauri</CardTitle>
                                <CardDescription>
                                    Manage GCMD controlled vocabularies for scientific keywords, platforms, and instruments.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ThesaurusCard
                                    thesauri={thesauri.map((t) => {
                                        const formData = data.thesauri.find((d) => d.type === t.type);
                                        return {
                                            ...t,
                                            isActive: formData?.isActive ?? t.isActive,
                                            isElmoActive: formData?.isElmoActive ?? t.isElmoActive,
                                        };
                                    })}
                                    onActiveChange={handleThesaurusActiveChange}
                                    onElmoActiveChange={handleThesaurusElmoActiveChange}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <Button type="submit" className="self-start" disabled={processing}>
                    Save
                </Button>
            </form>
        </AppLayout>
    );
}
