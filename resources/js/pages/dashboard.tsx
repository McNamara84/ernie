import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import { latestVersion } from '@/lib/version';
import { changelog as changelogRoute, dashboard,editor as editorRoute } from '@/routes';
import { uploadXml as uploadXmlRoute } from '@/routes/dashboard';
import { type BreadcrumbItem, type SharedData } from '@/types';

type UploadedAffiliation = {
    value?: string | null;
    rorId?: string | null;
};

type UploadedAuthor =
    | {
          type?: 'person';
          firstName?: string | null;
          lastName?: string | null;
          orcid?: string | null;
          affiliations?: (UploadedAffiliation | null | undefined)[] | null;
      }
    | {
          type: 'institution';
          institutionName?: string | null;
          affiliations?: (UploadedAffiliation | null | undefined)[] | null;
      };

type UploadedContributor =
    | {
          type?: 'person';
          roles?: (string | null | undefined)[] | Record<string, unknown> | string | null;
          firstName?: string | null;
          lastName?: string | null;
          orcid?: string | null;
          affiliations?: (UploadedAffiliation | null | undefined)[] | null;
      }
    | {
          type: 'institution';
          roles?: (string | null | undefined)[] | Record<string, unknown> | string | null;
          institutionName?: string | null;
          affiliations?: (UploadedAffiliation | null | undefined)[] | null;
      };

export const handleXmlFiles = async (files: File[]): Promise<void> => {
    if (!files.length) return;

    const csrfHeaders = buildCsrfHeaders();
    const token = csrfHeaders['X-CSRF-TOKEN'];

    if (!token) {
        throw new Error('CSRF token not found');
    }

    const formData = new FormData();
    formData.append('file', files[0]);

    try {
        const response = await fetch(uploadXmlRoute.url(), {
            method: 'POST',
            body: formData,
            headers: csrfHeaders,
            credentials: 'same-origin',
        });
        
        if (!response.ok) {
            let message = 'Upload failed';
            try {
                const errorData: { message?: string } = await response.json();
                message = errorData.message ?? message;
            } catch (err) {
                console.error('Failed to parse error response', err);
            }
            throw new Error(message);
        }
        const data: {
            doi?: string | null;
            year?: string | null;
            version?: string | null;
            language?: string | null;
            resourceType?: string | null;
            titles?: { title: string; titleType: string }[] | null;
            licenses?: string[] | null;
            authors?: (UploadedAuthor | null | undefined)[] | null;
            contributors?: (UploadedContributor | null | undefined)[] | null;
            descriptions?: { type: string; description: string }[] | null;
            dates?: { dateType: string; startDate: string; endDate: string }[] | null;
            coverages?: {
                id?: string;
                latMin?: string;
                latMax?: string;
                lonMin?: string;
                lonMax?: string;
                startDate?: string;
                endDate?: string;
                startTime?: string;
                endTime?: string;
                timezone?: string;
                description?: string;
            }[] | null;
            gcmdKeywords?: { uuid: string; id: string; path: string[]; scheme: string }[] | null;
            freeKeywords?: string[] | null;
            mslKeywords?: {
                id: string;
                text: string;
                path: string;
                language: string;
                scheme: string;
                schemeURI: string;
            }[] | null;
            fundingReferences?: {
                funderName: string;
                funderIdentifier: string | null;
                funderIdentifierType: string | null;
                awardNumber: string | null;
                awardUri: string | null;
                awardTitle: string | null;
            }[] | null;
            mslLaboratories?: {
                identifier: string;
                name: string;
                affiliation_name: string;
                affiliation_ror: string;
            }[] | null;
        } = await response.json();
        const query: Record<string, string | number> = {};
        if (data.doi) query.doi = data.doi;
        if (data.year) query.year = data.year;
        if (data.version) query.version = data.version;
        if (data.language) query.language = data.language;
        if (data.resourceType) query.resourceType = data.resourceType;
        if (data.titles && data.titles.length > 0) {
            data.titles.forEach((t, i) => {
                query[`titles[${i}][title]`] = t.title;
                query[`titles[${i}][titleType]`] = t.titleType;
            });
        }
        if (data.licenses && data.licenses.length > 0) {
            data.licenses.forEach((l, i) => {
                query[`licenses[${i}]`] = l;
            });
        }
        if (data.authors && data.authors.length > 0) {
            data.authors.forEach((author, authorIndex) => {
                if (!author || typeof author !== 'object') {
                    return;
                }

                const type = author.type === 'institution' ? 'institution' : 'person';
                query[`authors[${authorIndex}][type]`] = type;

                if (type === 'person') {
                    const trimmedFirst =
                        typeof author.firstName === 'string' ? author.firstName.trim() : '';
                    const trimmedLast =
                        typeof author.lastName === 'string' ? author.lastName.trim() : '';
                    const trimmedOrcid =
                        typeof author.orcid === 'string' ? author.orcid.trim() : '';

                    if (trimmedFirst) {
                        query[`authors[${authorIndex}][firstName]`] = trimmedFirst;
                    }
                    if (trimmedLast) {
                        query[`authors[${authorIndex}][lastName]`] = trimmedLast;
                    }
                    if (trimmedOrcid) {
                        query[`authors[${authorIndex}][orcid]`] = trimmedOrcid;
                    }
                } else if (
                    typeof author.institutionName === 'string' &&
                    author.institutionName.trim()
                ) {
                    query[`authors[${authorIndex}][institutionName]`] =
                        author.institutionName.trim();
                }

                const affiliations = Array.isArray(author.affiliations)
                    ? author.affiliations
                    : [];

                affiliations.forEach((affiliation, affiliationIndex) => {
                    if (!affiliation || typeof affiliation !== 'object') {
                        return;
                    }

                    const value =
                        typeof affiliation.value === 'string' ? affiliation.value.trim() : '';
                    const rorId =
                        typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

                    if (!value && !rorId) {
                        return;
                    }

                    if (value) {
                        query[`authors[${authorIndex}][affiliations][${affiliationIndex}][value]`] =
                            value;
                    }

                    if (rorId) {
                        query[`authors[${authorIndex}][affiliations][${affiliationIndex}][rorId]`] =
                            rorId;
                    }
                });
            });
        }
        if (data.contributors && data.contributors.length > 0) {
            data.contributors.forEach((contributor, contributorIndex) => {
                if (!contributor || typeof contributor !== 'object') {
                    return;
                }

                const rawRoles = Array.isArray(contributor.roles)
                    ? contributor.roles
                    : contributor.roles && typeof contributor.roles === 'object'
                      ? Object.values(contributor.roles)
                      : typeof contributor.roles === 'string'
                        ? [contributor.roles]
                        : [];
                const normalisedRoles = rawRoles
                    .map((role) =>
                        typeof role === 'string' ? normaliseContributorRoleLabel(role) : '',
                    )
                    .filter((role): role is string => role.length > 0);

                const type = inferContributorTypeFromRoles(contributor.type, normalisedRoles);
                query[`contributors[${contributorIndex}][type]`] = type;

                normalisedRoles.forEach((role, roleIndex) => {
                    query[`contributors[${contributorIndex}][roles][${roleIndex}]`] = role;
                });

                if (type === 'person') {
                    const trimmedFirst =
                        typeof contributor.firstName === 'string' ? contributor.firstName.trim() : '';
                    const trimmedLast =
                        typeof contributor.lastName === 'string' ? contributor.lastName.trim() : '';
                    const trimmedOrcid =
                        typeof contributor.orcid === 'string' ? contributor.orcid.trim() : '';

                    if (trimmedFirst) {
                        query[`contributors[${contributorIndex}][firstName]`] = trimmedFirst;
                    }

                    if (trimmedLast) {
                        query[`contributors[${contributorIndex}][lastName]`] = trimmedLast;
                    }

                    if (trimmedOrcid) {
                        query[`contributors[${contributorIndex}][orcid]`] = trimmedOrcid;
                    }
                } else if (
                    typeof contributor.institutionName === 'string' &&
                    contributor.institutionName.trim()
                ) {
                    query[`contributors[${contributorIndex}][institutionName]`] =
                        contributor.institutionName.trim();
                }

                const affiliations = Array.isArray(contributor.affiliations)
                    ? contributor.affiliations
                    : [];

                affiliations.forEach((affiliation, affiliationIndex) => {
                    if (!affiliation || typeof affiliation !== 'object') {
                        return;
                    }

                    const value =
                        typeof affiliation.value === 'string' ? affiliation.value.trim() : '';
                    const rorId =
                        typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

                    if (!value && !rorId) {
                        return;
                    }

                    if (value) {
                        query[`contributors[${contributorIndex}][affiliations][${affiliationIndex}][value]`] =
                            value;
                    }

                    if (rorId) {
                        query[`contributors[${contributorIndex}][affiliations][${affiliationIndex}][rorId]`] =
                            rorId;
                    }
                });
            });
        }
        if (data.descriptions && data.descriptions.length > 0) {
            data.descriptions.forEach((desc, i) => {
                if (!desc || typeof desc !== 'object') {
                    return;
                }

                const type = typeof desc.type === 'string' ? desc.type.trim() : '';
                const description =
                    typeof desc.description === 'string' ? desc.description.trim() : '';

                if (!type || !description) {
                    return;
                }

                query[`descriptions[${i}][type]`] = type;
                query[`descriptions[${i}][description]`] = description;
            });
        }
        if (data.dates && data.dates.length > 0) {
            data.dates.forEach((date, i) => {
                if (!date || typeof date !== 'object') {
                    return;
                }

                const dateType = typeof date.dateType === 'string' ? date.dateType.trim() : '';
                const startDate = typeof date.startDate === 'string' ? date.startDate.trim() : '';
                const endDate = typeof date.endDate === 'string' ? date.endDate.trim() : '';

                if (!dateType || (!startDate && !endDate)) {
                    return;
                }

                query[`dates[${i}][dateType]`] = dateType;
                if (startDate) {
                    query[`dates[${i}][startDate]`] = startDate;
                }
                if (endDate) {
                    query[`dates[${i}][endDate]`] = endDate;
                }
            });
        }
        if (data.gcmdKeywords && data.gcmdKeywords.length > 0) {
            data.gcmdKeywords.forEach((keyword, i) => {
                if (!keyword || typeof keyword !== 'object') {
                    return;
                }

                const id = typeof keyword.id === 'string' ? keyword.id.trim() : '';
                const scheme = typeof keyword.scheme === 'string' ? keyword.scheme.trim() : '';
                const path = Array.isArray(keyword.path) ? keyword.path : [];

                if (!id || !scheme || path.length === 0) {
                    return;
                }

                query[`gcmdKeywords[${i}][id]`] = id;
                query[`gcmdKeywords[${i}][scheme]`] = scheme;
                query[`gcmdKeywords[${i}][path]`] = path.join(' > ');
                query[`gcmdKeywords[${i}][text]`] = path[path.length - 1] || '';
            });
        }
        if (data.mslKeywords && data.mslKeywords.length > 0) {
            const gcmdIndex = data.gcmdKeywords ? data.gcmdKeywords.length : 0;
            data.mslKeywords.forEach((keyword, i) => {
                if (!keyword || typeof keyword !== 'object') {
                    return;
                }

                const id = typeof keyword.id === 'string' ? keyword.id.trim() : '';
                const path = typeof keyword.path === 'string' ? keyword.path.trim() : '';
                const text = typeof keyword.text === 'string' ? keyword.text.trim() : '';
                const language = typeof keyword.language === 'string' ? keyword.language.trim() : 'en';
                const scheme = typeof keyword.scheme === 'string' ? keyword.scheme.trim() : '';
                const schemeURI = typeof keyword.schemeURI === 'string' ? keyword.schemeURI.trim() : '';

                if (!id || !path) {
                    return;
                }

                const index = gcmdIndex + i;
                query[`gcmdKeywords[${index}][id]`] = id;
                query[`gcmdKeywords[${index}][path]`] = path;
                query[`gcmdKeywords[${index}][text]`] = text;
                query[`gcmdKeywords[${index}][language]`] = language;
                query[`gcmdKeywords[${index}][scheme]`] = scheme;
                query[`gcmdKeywords[${index}][schemeURI]`] = schemeURI;
            });
        }
        if (data.freeKeywords && data.freeKeywords.length > 0) {
            data.freeKeywords.forEach((keyword, i) => {
                if (typeof keyword === 'string' && keyword.trim()) {
                    query[`freeKeywords[${i}]`] = keyword.trim();
                }
            });
        }
        if (data.coverages && data.coverages.length > 0) {
            data.coverages.forEach((coverage, i) => {
                if (!coverage || typeof coverage !== 'object') {
                    return;
                }

                // Optional fields - only add if present
                const fields = ['id', 'latMin', 'latMax', 'lonMin', 'lonMax', 'startDate', 'endDate', 'startTime', 'endTime', 'timezone', 'description'] as const;
                
                fields.forEach((field) => {
                    const value = coverage[field];
                    if (typeof value === 'string' && value.trim()) {
                        query[`coverages[${i}][${field}]`] = value.trim();
                    }
                });
            });
        }
        
        // Add funding references as JSON string (like relatedWorks)
        if (data.fundingReferences && data.fundingReferences.length > 0) {
            query.fundingReferences = JSON.stringify(data.fundingReferences);
        }
        if (data.mslLaboratories && data.mslLaboratories.length > 0) {
            query.mslLaboratories = JSON.stringify(data.mslLaboratories);
        }
        
        router.get(editorRoute({ query }).url);
    } catch (error) {
        console.error('XML upload failed', error);
        if (error instanceof Error) {
            throw new Error(`Upload failed: ${error.message}`);
        }
        throw new Error('Upload failed');
    }
};

type DashboardProps = {
    onXmlFiles?: (files: File[]) => Promise<void>;
};

function filterXmlFiles(files: File[]): File[] {
    return files.filter((file) => file.type === 'text/xml' || file.name.endsWith('.xml'));
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

type DashboardPageProps = SharedData & {
    resourceCount?: number;
};

export default function Dashboard({ onXmlFiles = handleXmlFiles }: DashboardProps = {}) {
    const { auth, resourceCount } = usePage<DashboardPageProps>().props;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const datasetCount = typeof resourceCount === 'number' ? resourceCount : 0;

    async function uploadXml(files: File[]) {
        try {
            await onXmlFiles(files);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Upload failed');
        }
    }

    function handleDragOver(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setIsDragging(true);
    }

    function handleDragLeave(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        const related = event.relatedTarget as Node | null;
        if (!related || !event.currentTarget.contains(related)) {
            setIsDragging(false);
        }
    }

    function handleDrop(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setIsDragging(false);
        const files = Array.from(event.dataTransfer.files);
        const xmlFiles = filterXmlFiles(files);
        if (xmlFiles.length) {
            void uploadXml(xmlFiles);
        }
    }

    function handleFileSelect(event: React.ChangeEvent<HTMLInputElement>) {
        const files = event.target.files;
        if (files && files.length) {
            const xmlFiles = filterXmlFiles(Array.from(files));
            if (xmlFiles.length) {
                void uploadXml(xmlFiles);
            }
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {error && (
                    <Alert variant="destructive">
                        <AlertTitle>Upload error</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Hello {auth.user.name}!</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Nice to see you today! You still have x datasets to curate. Have fun, your ERNIE!
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Statistics</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <strong className="font-semibold text-foreground">{datasetCount}</strong>{' '}
                            datasets from y data centers of z institutions
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Environment</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <table className="w-full">
                                <tbody>
                                    <tr>
                                        <td className="py-1">ERNIE Version</td>
                                        <td className="py-1 text-right">
                                            <Link
                                                href={changelogRoute().url}
                                                aria-label={`View changelog for version ${latestVersion}`}
                                            >
                                                <Badge className="w-14 bg-[#003da6] text-white">
                                                    {latestVersion}
                                                </Badge>
                                            </Link>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="py-1">PHP Version</td>
                                        <td className="py-1 text-right">
                                            <Badge className="w-14 bg-[#777BB4] text-white">8.4.12</Badge>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="py-1">Laravel Version</td>
                                        <td className="py-1 text-right">
                                            <Badge className="w-14 bg-[#FF2D20] text-white">12.28.1</Badge>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>
                <Card className="flex flex-col items-center justify-center">
                    <CardHeader className="items-center text-center">
                        <CardTitle>Dropzone for XML files</CardTitle>
                        <CardDescription>
                            Here you can upload new XML files sent by ELMO for curation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex w-full justify-center">
                        <div
                            onDrop={handleDrop}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            className={`flex w-full flex-col items-center justify-center rounded-md border-2 border-dashed p-12 text-center ${
                                isDragging ? 'bg-accent' : 'bg-muted'
                            }`}
                        >
                            <p className="mb-4 text-sm text-muted-foreground">Drag &amp; drop XML files here</p>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".xml"
                                className="hidden"
                                onChange={handleFileSelect}
                            />
                            <Button type="button" onClick={() => fileInputRef.current?.click()}>Upload</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

