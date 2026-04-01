import { Head, useForm } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { ChevronDown, ChevronRight, Database, Globe, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { toast } from 'sonner';

import { type ContributorRoleRow, ContributorRolesCard } from '@/components/settings/contributor-roles-card';
import { LicenseResourceTypePopover } from '@/components/settings/license-resource-type-popover';
import { type PidSettingData, PidSettingsCard } from '@/components/settings/pid-settings-card';
import { ThesaurusCard, type ThesaurusData } from '@/components/settings/thesaurus-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { getSelectAllState } from '@/lib/select-all';
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
}

interface DescriptionTypeRow {
    id: number;
    name: string;
    slug: string;
    active: boolean;
    elmo_active: boolean;
}

interface LandingPageDomainRow {
    id: number;
    domain: string;
}

interface DatacenterRow {
    id: number;
    name: string;
    resources_count: number;
}

interface RelationTypeRow {
    id: number;
    name: string;
    slug: string;
    active: boolean;
    elmo_active: boolean;
}

interface IdentifierTypePatternRow {
    id: number;
    type: 'validation' | 'detection';
    pattern: string;
    is_active: boolean;
    priority: number;
}

interface IdentifierTypeRow {
    id: number;
    name: string;
    slug: string;
    active: boolean;
    elmo_active: boolean;
    patterns: IdentifierTypePatternRow[];
}

interface EditorSettingsProps {
    resourceTypes: ResourceTypeRow[];
    titleTypes: TitleTypeRow[];
    licenses: LicenseRow[];
    languages: LanguageRow[];
    dateTypes: DateTypeRow[];
    descriptionTypes: DescriptionTypeRow[];
    maxTitles: number;
    maxLicenses: number;
    thesauri: ThesaurusData[];
    pidSettings: PidSettingData[];
    landingPageDomains: LandingPageDomainRow[];
    contributorPersonRoles: ContributorRoleRow[];
    contributorInstitutionRoles: ContributorRoleRow[];
    contributorBothRoles: ContributorRoleRow[];
    relationTypes: RelationTypeRow[];
    identifierTypes: IdentifierTypeRow[];
    datacenters: DatacenterRow[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Editor Settings', href: settings().url }];

export default function EditorSettings({
    resourceTypes,
    titleTypes,
    licenses,
    languages,
    dateTypes,
    descriptionTypes,
    maxTitles,
    maxLicenses,
    thesauri,
    pidSettings,
    landingPageDomains,
    contributorPersonRoles,
    contributorInstitutionRoles,
    contributorBothRoles,
    relationTypes,
    identifierTypes,
    datacenters: initialDatacenters,
}: EditorSettingsProps) {
    // Landing page domains - managed separately via API (not part of main form)
    const [domains, setDomains] = useState<LandingPageDomainRow[]>(landingPageDomains);
    const [newDomain, setNewDomain] = useState('');
    const [isAddingDomain, setIsAddingDomain] = useState(false);
    const [expandedIdentifierTypes, setExpandedIdentifierTypes] = useState<Set<number>>(new Set());

    // Datacenter management - managed separately via API
    const [datacenters, setDatacenters] = useState<DatacenterRow[]>(initialDatacenters);
    const [newDatacenter, setNewDatacenter] = useState('');
    const [isAddingDatacenter, setIsAddingDatacenter] = useState(false);

    const handleAddDomain = async () => {
        if (!newDomain.trim()) return;

        setIsAddingDomain(true);
        try {
            const response = await axios.post<{ domain: LandingPageDomainRow; message: string }>('/api/landing-page-domains', {
                domain: newDomain.trim(),
            });
            setDomains((prev) => [...prev, response.data.domain].sort((a, b) => a.domain.localeCompare(b.domain)));
            setNewDomain('');
            toast.success(response.data.message);
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.errors?.domain) {
                toast.error(error.response.data.errors.domain[0]);
            } else if (isAxiosError(error) && error.response?.data?.message) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Failed to add domain');
            }
        } finally {
            setIsAddingDomain(false);
        }
    };

    const handleDeleteDomain = async (domainId: number) => {
        if (!confirm('Are you sure you want to delete this domain? It cannot be deleted if it is used by any landing page.')) return;

        try {
            const response = await axios.delete<{ message: string }>(`/api/landing-page-domains/${domainId}`);
            setDomains((prev) => prev.filter((d) => d.id !== domainId));
            toast.success(response.data.message);
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.message) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Failed to delete domain');
            }
        }
    };

    const handleAddDatacenter = async () => {
        if (!newDatacenter.trim()) return;

        setIsAddingDatacenter(true);
        try {
            const response = await axios.post<{ datacenter: DatacenterRow; message: string }>('/api/datacenters', {
                name: newDatacenter.trim(),
            });
            setDatacenters((prev) => [...prev, response.data.datacenter].sort((a, b) => a.name.localeCompare(b.name)));
            setNewDatacenter('');
            toast.success(response.data.message);
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.errors?.name) {
                toast.error(error.response.data.errors.name[0]);
            } else if (isAxiosError(error) && error.response?.data?.message) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Failed to add datacenter');
            }
        } finally {
            setIsAddingDatacenter(false);
        }
    };

    const handleDeleteDatacenter = async (datacenterId: number) => {
        if (!confirm('Are you sure you want to delete this datacenter? It cannot be deleted if it is assigned to any resource.')) return;

        try {
            const response = await axios.delete<{ message: string }>(`/api/datacenters/${datacenterId}`);
            setDatacenters((prev) => prev.filter((d) => d.id !== datacenterId));
            toast.success(response.data.message);
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.message) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Failed to delete datacenter');
            }
        }
    };

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
        })),
        descriptionTypes: descriptionTypes.map((d) => ({
            id: d.id,
            name: d.name,
            slug: d.slug,
            active: d.slug === 'Abstract' ? true : d.active,
            elmo_active: d.slug === 'Abstract' ? true : d.elmo_active,
        })),
        maxTitles,
        maxLicenses,
        thesauri: thesauri.map((t) => ({
            type: t.type,
            isActive: t.isActive,
            isElmoActive: t.isElmoActive,
        })),
        pidSettings: pidSettings.map((p) => ({
            type: p.type,
            isActive: p.isActive,
            isElmoActive: p.isElmoActive,
        })),
        contributorPersonRoles: contributorPersonRoles.map((r) => ({
            id: r.id,
            name: r.name,
            slug: r.slug,
            category: r.category,
            active: r.active,
            elmo_active: r.elmo_active,
        })),
        contributorInstitutionRoles: contributorInstitutionRoles.map((r) => ({
            id: r.id,
            name: r.name,
            slug: r.slug,
            category: r.category,
            active: r.active,
            elmo_active: r.elmo_active,
        })),
        contributorBothRoles: contributorBothRoles.map((r) => ({
            id: r.id,
            name: r.name,
            slug: r.slug,
            category: r.category,
            active: r.active,
            elmo_active: r.elmo_active,
        })),
        relationTypes: relationTypes.map((r) => ({
            id: r.id,
            name: r.name,
            slug: r.slug,
            active: r.active,
            elmo_active: r.elmo_active,
        })),
        identifierTypes: identifierTypes.map((it) => ({
            id: it.id,
            name: it.name,
            slug: it.slug,
            active: it.active,
            elmo_active: it.elmo_active,
            patterns: it.patterns.map((p) => ({
                id: p.id,
                type: p.type,
                pattern: p.pattern,
                is_active: p.is_active,
                priority: p.priority,
            })),
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

    const handleDescriptionTypeActiveChange = (index: number, value: boolean) => {
        setData(
            'descriptionTypes',
            data.descriptionTypes.map((d, i) => (i === index ? { ...d, active: value } : d)),
        );
    };

    const handleDescriptionTypeElmoActiveChange = (index: number, value: boolean) => {
        setData(
            'descriptionTypes',
            data.descriptionTypes.map((d, i) => (i === index ? { ...d, elmo_active: value } : d)),
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

    const handleBulkThesaurusActiveChange = (isActive: boolean) => {
        setData(
            'thesauri',
            data.thesauri.map((t) => ({ ...t, isActive })),
        );
    };

    const handleBulkThesaurusElmoActiveChange = (isElmoActive: boolean) => {
        setData(
            'thesauri',
            data.thesauri.map((t) => ({ ...t, isElmoActive })),
        );
    };

    const handlePidActiveChange = (type: string, isActive: boolean) => {
        setData(
            'pidSettings',
            data.pidSettings.map((p) => (p.type === type ? { ...p, isActive } : p)),
        );
    };

    const handlePidElmoActiveChange = (type: string, isElmoActive: boolean) => {
        setData(
            'pidSettings',
            data.pidSettings.map((p) => (p.type === type ? { ...p, isElmoActive } : p)),
        );
    };

    const handleContributorRoleChange = (
        arrayKey: 'contributorPersonRoles' | 'contributorInstitutionRoles' | 'contributorBothRoles',
        index: number,
        field: 'active' | 'elmo_active' | 'category',
        value: boolean | string,
    ) => {
        if (field === 'category') {
            const categoryToKey = {
                person: 'contributorPersonRoles',
                institution: 'contributorInstitutionRoles',
                both: 'contributorBothRoles',
            } as const;
            const targetKey = categoryToKey[value as 'person' | 'institution' | 'both'];

            if (targetKey && targetKey !== arrayKey) {
                const role = { ...data[arrayKey][index], category: value as 'person' | 'institution' | 'both' };
                setData({
                    ...data,
                    [arrayKey]: data[arrayKey].filter((_, i) => i !== index),
                    [targetKey]: [...data[targetKey], role],
                });
                return;
            }
        }

        setData(
            arrayKey,
            data[arrayKey].map((r, i) => (i === index ? { ...r, [field]: value } : r)),
        );
    };

    // Select-all state for each card's ERNIE / ELMO columns
    const licenseErnieState = getSelectAllState(data.licenses.map((l) => l.active));
    const licenseElmoState = getSelectAllState(data.licenses.map((l) => l.elmo_active));
    const rtErnieState = getSelectAllState(data.resourceTypes.map((r) => r.active));
    const rtElmoState = getSelectAllState(data.resourceTypes.map((r) => r.elmo_active));
    const ttErnieState = getSelectAllState(data.titleTypes.map((t) => t.active));
    const ttElmoState = getSelectAllState(data.titleTypes.map((t) => t.elmo_active));
    const langErnieState = getSelectAllState(data.languages.map((l) => l.active));
    const langElmoState = getSelectAllState(data.languages.map((l) => l.elmo_active));
    const dtErnieState = getSelectAllState(data.dateTypes.map((d) => d.active));
    const descTypeErnieState = getSelectAllState(
        data.descriptionTypes.filter((d) => d.slug !== 'Abstract').map((d) => d.active),
    );
    const descTypeElmoState = getSelectAllState(
        data.descriptionTypes.filter((d) => d.slug !== 'Abstract').map((d) => d.elmo_active),
    );
    const relTypeErnieState = getSelectAllState(data.relationTypes.map((r) => r.active));
    const relTypeElmoState = getSelectAllState(data.relationTypes.map((r) => r.elmo_active));
    const idTypeErnieState = getSelectAllState(data.identifierTypes.map((it) => it.active));
    const idTypeElmoState = getSelectAllState(data.identifierTypes.map((it) => it.elmo_active));
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={licenseErnieState.allChecked}
                                                            indeterminate={licenseErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'licenses',
                                                                    data.licenses.map((l) => ({ ...l, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Licenses"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={licenseElmoState.allChecked}
                                                            indeterminate={licenseElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'licenses',
                                                                    data.licenses.map((l) => ({ ...l, elmo_active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Licenses"
                                                        />
                                                    </div>
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

                        {/* Landing Page Domains */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Globe className="size-5" />
                                    Landing Page Domains
                                </CardTitle>
                                <CardDescription>
                                    Manage domains available for external landing pages. These domains can be selected when setting up an external
                                    landing page for a resource.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {/* Add new domain */}
                                    <div className="flex gap-2">
                                        <Input
                                            placeholder="https://example.org/"
                                            value={newDomain}
                                            onChange={(e) => setNewDomain(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    handleAddDomain();
                                                }
                                            }}
                                        />
                                        <Button type="button" onClick={handleAddDomain} disabled={isAddingDomain || !newDomain.trim()}>
                                            {isAddingDomain ? 'Adding...' : 'Add'}
                                        </Button>
                                    </div>

                                    {/* Domain list */}
                                    {domains.length > 0 ? (
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Domain</TableHead>
                                                        <TableHead className="w-16 text-center">Actions</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {domains.map((domain) => (
                                                        <TableRow key={domain.id}>
                                                            <TableCell className="font-mono text-sm">{domain.domain}</TableCell>
                                                            <TableCell className="text-center">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => handleDeleteDomain(domain.id)}
                                                                    title="Delete domain"
                                                                    aria-label="Delete domain"
                                                                >
                                                                    <Trash2 className="size-4 text-destructive" aria-hidden="true" />
                                                                </Button>
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No domains configured yet. Add a domain URL above to get started.
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Datacenters */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Database className="size-5" />
                                    Datacenters
                                </CardTitle>
                                <CardDescription>
                                    Manage datacenters that can be assigned to resources. Each resource must be assigned to at least one datacenter.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {/* Add new datacenter */}
                                    <div className="flex gap-2">
                                        <Input
                                            placeholder="Datacenter name"
                                            value={newDatacenter}
                                            onChange={(e) => setNewDatacenter(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                    handleAddDatacenter();
                                                }
                                            }}
                                        />
                                        <Button type="button" onClick={handleAddDatacenter} disabled={isAddingDatacenter || !newDatacenter.trim()}>
                                            {isAddingDatacenter ? 'Adding...' : 'Add'}
                                        </Button>
                                    </div>

                                    {/* Datacenter list */}
                                    {datacenters.length > 0 ? (
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Name</TableHead>
                                                        <TableHead className="w-24 text-center">Resources</TableHead>
                                                        <TableHead className="w-16 text-center">Actions</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {datacenters.map((dc) => (
                                                        <TableRow key={dc.id}>
                                                            <TableCell>{dc.name}</TableCell>
                                                            <TableCell className="text-center">{dc.resources_count}</TableCell>
                                                            <TableCell className="text-center">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => handleDeleteDatacenter(dc.id)}
                                                                    disabled={dc.resources_count > 0}
                                                                    title={dc.resources_count > 0 ? 'Cannot delete: datacenter is assigned to resources' : 'Delete datacenter'}
                                                                    aria-label="Delete datacenter"
                                                                >
                                                                    <Trash2 className="size-4 text-destructive" aria-hidden="true" />
                                                                </Button>
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    ) : (
                                        <p className="text-muted-foreground text-sm">
                                            No datacenters configured yet. Add a datacenter name above to get started.
                                        </p>
                                    )}
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={rtErnieState.allChecked}
                                                            indeterminate={rtErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'resourceTypes',
                                                                    data.resourceTypes.map((r) => ({ ...r, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Resource Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={rtElmoState.allChecked}
                                                            indeterminate={rtElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'resourceTypes',
                                                                    data.resourceTypes.map((r) => ({ ...r, elmo_active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Resource Types"
                                                        />
                                                    </div>
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={ttErnieState.allChecked}
                                                            indeterminate={ttErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'titleTypes',
                                                                    data.titleTypes.map((t) => ({ ...t, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Title Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={ttElmoState.allChecked}
                                                            indeterminate={ttElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'titleTypes',
                                                                    data.titleTypes.map((t) => ({ ...t, elmo_active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Title Types"
                                                        />
                                                    </div>
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={langErnieState.allChecked}
                                                            indeterminate={langErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'languages',
                                                                    data.languages.map((l) => ({ ...l, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Languages"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={langElmoState.allChecked}
                                                            indeterminate={langElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'languages',
                                                                    data.languages.map((l) => ({ ...l, elmo_active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Languages"
                                                        />
                                                    </div>
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={dtErnieState.allChecked}
                                                            indeterminate={dtErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'dateTypes',
                                                                    data.dateTypes.map((d) => ({ ...d, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Date Types"
                                                        />
                                                    </div>
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
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Description Types */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Description Types</CardTitle>
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={descTypeErnieState.allChecked}
                                                            indeterminate={descTypeErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'descriptionTypes',
                                                                    data.descriptionTypes.map((d) =>
                                                                        d.slug === 'Abstract' ? d : { ...d, active: checked === true },
                                                                    ),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Description Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={descTypeElmoState.allChecked}
                                                            indeterminate={descTypeElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'descriptionTypes',
                                                                    data.descriptionTypes.map((d) =>
                                                                        d.slug === 'Abstract' ? d : { ...d, elmo_active: checked === true },
                                                                    ),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Description Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.descriptionTypes.map((descType, index) => {
                                                const isAbstract = descType.slug === 'Abstract';
                                                return (
                                                    <TableRow key={descType.id}>
                                                        <TableCell>{descType.id}</TableCell>
                                                        <TableCell>{descType.name}</TableCell>
                                                        <TableCell>{descType.slug}</TableCell>
                                                        <TableCell className="text-center">
                                                            <Label htmlFor={`desc-active-${descType.id}`} className="sr-only">
                                                                ERNIE active
                                                            </Label>
                                                            <Checkbox
                                                                id={`desc-active-${descType.id}`}
                                                                checked={descType.active}
                                                                disabled={isAbstract}
                                                                onCheckedChange={(checked) =>
                                                                    handleDescriptionTypeActiveChange(index, checked === true)
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            <Label htmlFor={`desc-elmo-active-${descType.id}`} className="sr-only">
                                                                ELMO active
                                                            </Label>
                                                            <Checkbox
                                                                id={`desc-elmo-active-${descType.id}`}
                                                                checked={descType.elmo_active}
                                                                disabled={isAbstract}
                                                                onCheckedChange={(checked) =>
                                                                    handleDescriptionTypeElmoActiveChange(index, checked === true)
                                                                }
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
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
                                    onBulkActiveChange={handleBulkThesaurusActiveChange}
                                    onBulkElmoActiveChange={handleBulkThesaurusElmoActiveChange}
                                />
                            </CardContent>
                        </Card>

                        {/* Persistent Identifiers */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Persistent Identifiers</CardTitle>
                                <CardDescription>
                                    Manage persistent identifier registries: PID4INST (b2inst) for research instruments and ROR for
                                    research organizations.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <PidSettingsCard
                                    pidSettings={pidSettings.map((p) => {
                                        const formData = data.pidSettings.find((d) => d.type === p.type);
                                        return {
                                            ...p,
                                            isActive: formData?.isActive ?? p.isActive,
                                            isElmoActive: formData?.isElmoActive ?? p.isElmoActive,
                                        };
                                    })}
                                    onActiveChange={handlePidActiveChange}
                                    onElmoActiveChange={handlePidElmoActiveChange}
                                />
                            </CardContent>
                        </Card>

                        {/* Contributor Roles (Persons) */}
                        <ContributorRolesCard
                            title="Contributor Roles (Persons)"
                            description="Contributor types applicable to person contributors."
                            roles={data.contributorPersonRoles}
                            dataKey="contributorPersonRoles"
                            onRoleChange={(index, field, value) =>
                                handleContributorRoleChange('contributorPersonRoles', index, field, value)
                            }
                            onSetAll={(roles) => setData('contributorPersonRoles', roles)}
                        />

                        {/* Contributor Roles (Institutions) */}
                        <ContributorRolesCard
                            title="Contributor Roles (Institutions)"
                            description="Contributor types applicable to institution contributors."
                            roles={data.contributorInstitutionRoles}
                            dataKey="contributorInstitutionRoles"
                            onRoleChange={(index, field, value) =>
                                handleContributorRoleChange('contributorInstitutionRoles', index, field, value)
                            }
                            onSetAll={(roles) => setData('contributorInstitutionRoles', roles)}
                        />

                        {/* Contributor Roles (Both) */}
                        <ContributorRolesCard
                            title="Contributor Roles (Both)"
                            description="Contributor types applicable to both person and institution contributors."
                            roles={data.contributorBothRoles}
                            dataKey="contributorBothRoles"
                            onRoleChange={(index, field, value) =>
                                handleContributorRoleChange('contributorBothRoles', index, field, value)
                            }
                            onSetAll={(roles) => setData('contributorBothRoles', roles)}
                        />

                        {/* Relation Types */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Relation Types</CardTitle>
                                <CardDescription>DataCite relationType values for Related Identifiers.</CardDescription>
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
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={relTypeErnieState.allChecked}
                                                            indeterminate={relTypeErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'relationTypes',
                                                                    data.relationTypes.map((r) => ({ ...r, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Relation Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={relTypeElmoState.allChecked}
                                                            indeterminate={relTypeElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'relationTypes',
                                                                    data.relationTypes.map((r) => ({ ...r, elmo_active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Relation Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.relationTypes.map((relType, index) => (
                                                <TableRow key={relType.id}>
                                                    <TableCell>{relType.id}</TableCell>
                                                    <TableCell>{relType.name}</TableCell>
                                                    <TableCell className="font-mono text-sm">{relType.slug}</TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`rel-active-${relType.id}`} className="sr-only">
                                                            ERNIE active
                                                        </Label>
                                                        <Checkbox
                                                            id={`rel-active-${relType.id}`}
                                                            checked={relType.active}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'relationTypes',
                                                                    data.relationTypes.map((r, i) =>
                                                                        i === index ? { ...r, active: checked === true } : r,
                                                                    ),
                                                                );
                                                            }}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Label htmlFor={`rel-elmo-active-${relType.id}`} className="sr-only">
                                                            ELMO active
                                                        </Label>
                                                        <Checkbox
                                                            id={`rel-elmo-active-${relType.id}`}
                                                            checked={relType.elmo_active}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'relationTypes',
                                                                    data.relationTypes.map((r, i) =>
                                                                        i === index ? { ...r, elmo_active: checked === true } : r,
                                                                    ),
                                                                );
                                                            }}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Identifier Types */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Identifier Types</CardTitle>
                                <CardDescription>
                                    DataCite relatedIdentifierType values with validation and detection patterns.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-8" />
                                                <TableHead>ID</TableHead>
                                                <TableHead>Name</TableHead>
                                                <TableHead>Slug</TableHead>
                                                <TableHead className="text-center">
                                                    ERNIE
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={idTypeErnieState.allChecked}
                                                            indeterminate={idTypeErnieState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'identifierTypes',
                                                                    data.identifierTypes.map((it) => ({ ...it, active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ERNIE active for Identifier Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">
                                                    ELMO
                                                    <br />
                                                    active
                                                    <div className="mt-1">
                                                        <Checkbox
                                                            checked={idTypeElmoState.allChecked}
                                                            indeterminate={idTypeElmoState.indeterminate}
                                                            onCheckedChange={(checked) => {
                                                                setData(
                                                                    'identifierTypes',
                                                                    data.identifierTypes.map((it) => ({ ...it, elmo_active: checked === true })),
                                                                );
                                                            }}
                                                            aria-label="Select all ELMO active for Identifier Types"
                                                        />
                                                    </div>
                                                </TableHead>
                                                <TableHead className="text-center">Patterns</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.identifierTypes.map((idType, typeIndex) => {
                                                const isExpanded = expandedIdentifierTypes.has(idType.id);
                                                return (
                                                    <Fragment key={idType.id}>
                                                        <TableRow>
                                                            <TableCell>
                                                                {idType.patterns.length > 0 && (
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="size-6"
                                                                        onClick={() => {
                                                                            setExpandedIdentifierTypes((prev) => {
                                                                                const next = new Set(prev);
                                                                                if (next.has(idType.id)) {
                                                                                    next.delete(idType.id);
                                                                                } else {
                                                                                    next.add(idType.id);
                                                                                }
                                                                                return next;
                                                                            });
                                                                        }}
                                                                        aria-label={isExpanded ? 'Collapse patterns' : 'Expand patterns'}
                                                                    >
                                                                        {isExpanded ? (
                                                                            <ChevronDown className="size-4" />
                                                                        ) : (
                                                                            <ChevronRight className="size-4" />
                                                                        )}
                                                                    </Button>
                                                                )}
                                                            </TableCell>
                                                            <TableCell>{idType.id}</TableCell>
                                                            <TableCell>{idType.name}</TableCell>
                                                            <TableCell className="font-mono text-sm">{idType.slug}</TableCell>
                                                            <TableCell className="text-center">
                                                                <Label htmlFor={`id-active-${idType.id}`} className="sr-only">
                                                                    ERNIE active
                                                                </Label>
                                                                <Checkbox
                                                                    id={`id-active-${idType.id}`}
                                                                    checked={idType.active}
                                                                    onCheckedChange={(checked) => {
                                                                        setData(
                                                                            'identifierTypes',
                                                                            data.identifierTypes.map((it, i) =>
                                                                                i === typeIndex ? { ...it, active: checked === true } : it,
                                                                            ),
                                                                        );
                                                                    }}
                                                                />
                                                            </TableCell>
                                                            <TableCell className="text-center">
                                                                <Label htmlFor={`id-elmo-active-${idType.id}`} className="sr-only">
                                                                    ELMO active
                                                                </Label>
                                                                <Checkbox
                                                                    id={`id-elmo-active-${idType.id}`}
                                                                    checked={idType.elmo_active}
                                                                    onCheckedChange={(checked) => {
                                                                        setData(
                                                                            'identifierTypes',
                                                                            data.identifierTypes.map((it, i) =>
                                                                                i === typeIndex ? { ...it, elmo_active: checked === true } : it,
                                                                            ),
                                                                        );
                                                                    }}
                                                                />
                                                            </TableCell>
                                                            <TableCell className="text-center text-sm text-muted-foreground">
                                                                {idType.patterns.length}
                                                            </TableCell>
                                                        </TableRow>
                                                        {isExpanded && (
                                                            <TableRow className="bg-muted/50">
                                                                <TableCell />
                                                                <TableCell colSpan={6} className="p-2">
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow>
                                                                                <TableHead className="text-xs">Type</TableHead>
                                                                                <TableHead className="text-xs">Pattern</TableHead>
                                                                                <TableHead className="text-center text-xs">Active</TableHead>
                                                                                <TableHead className="text-center text-xs">Priority</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {idType.patterns.map((pattern, patternIndex) => (
                                                                                <TableRow key={`pattern-${pattern.id}`}>
                                                                                    <TableCell className="text-xs text-muted-foreground">
                                                                                        {pattern.type}
                                                                                    </TableCell>
                                                                                    <TableCell>
                                                                                        <Input
                                                                                            value={pattern.pattern}
                                                                                            className="font-mono text-xs"
                                                                                            aria-label={`${idType.name} ${pattern.type} pattern`}
                                                                                            onChange={(e) => {
                                                                                                setData(
                                                                                                    'identifierTypes',
                                                                                                    data.identifierTypes.map((it, i) =>
                                                                                                        i === typeIndex
                                                                                                            ? {
                                                                                                                  ...it,
                                                                                                                  patterns: it.patterns.map((p, pi) =>
                                                                                                                      pi === patternIndex
                                                                                                                          ? { ...p, pattern: e.target.value }
                                                                                                                          : p,
                                                                                                                  ),
                                                                                                              }
                                                                                                            : it,
                                                                                                    ),
                                                                                                );
                                                                                            }}
                                                                                        />
                                                                                    </TableCell>
                                                                                    <TableCell className="text-center">
                                                                                        <Checkbox
                                                                                            checked={pattern.is_active}
                                                                                            aria-label={`${idType.name} ${pattern.type} pattern active`}
                                                                                            onCheckedChange={(checked) => {
                                                                                                setData(
                                                                                                    'identifierTypes',
                                                                                                    data.identifierTypes.map((it, i) =>
                                                                                                        i === typeIndex
                                                                                                            ? {
                                                                                                                  ...it,
                                                                                                                  patterns: it.patterns.map((p, pi) =>
                                                                                                                      pi === patternIndex
                                                                                                                          ? { ...p, is_active: checked === true }
                                                                                                                          : p,
                                                                                                                  ),
                                                                                                              }
                                                                                                            : it,
                                                                                                    ),
                                                                                                );
                                                                                            }}
                                                                                        />
                                                                                    </TableCell>
                                                                                    <TableCell className="text-center">
                                                                                        <Input
                                                                                            type="number"
                                                                                            min={0}
                                                                                            max={100}
                                                                                            value={pattern.priority}
                                                                                            className="mx-auto w-16 text-center text-xs"
                                                                                            aria-label={`${idType.name} ${pattern.type} pattern priority`}
                                                                                            onChange={(e) => {
                                                                                                setData(
                                                                                                    'identifierTypes',
                                                                                                    data.identifierTypes.map((it, i) =>
                                                                                                        i === typeIndex
                                                                                                            ? {
                                                                                                                  ...it,
                                                                                                                  patterns: it.patterns.map((p, pi) =>
                                                                                                                      pi === patternIndex
                                                                                                                          ? { ...p, priority: Number(e.target.value) }
                                                                                                                          : p,
                                                                                                                  ),
                                                                                                              }
                                                                                                            : it,
                                                                                                    ),
                                                                                                );
                                                                                            }}
                                                                                        />
                                                                                    </TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}
                                                    </Fragment>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
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
