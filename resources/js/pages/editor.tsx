import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import DataCiteForm, { type InitialAuthor, type InitialContributor, type RawRightsInput } from '@/components/curation/datacite-form';
import { type FundingReferenceEntry } from '@/components/curation/fields/funding-reference';
import { type SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { type WarmupResponse, warmupSession } from '@/lib/session-warmup';
import { editor } from '@/routes';
import {
    type BreadcrumbItem,
    type DateType,
    type DescriptionType,
    type InstrumentSelection,
    type Language,
    type License,
    type MSLLaboratory,
    type RelatedIdentifier,
    type ResourceType,
    type Role,
    type SharedData,
    type TitleType,
} from '@/types';

interface EditorProps {
    maxTitles: number;
    maxLicenses: number;
    googleMapsApiKey: string;
    doi?: string;
    year?: string;
    version?: string;
    language?: string;
    resourceType?: string;
    titles?: { title: string; titleType: string }[];
    initialLicenses?: string[];
    initialRawRights?: RawRightsInput[];
    resourceId?: string;
    authors?: InitialAuthor[];
    contributors?: InitialContributor[];
    descriptions?: { type: string; description: string }[];
    dates?: { dateType: string; startDate: string; endDate: string }[];
    gcmdKeywords?: { id: string; path: string; text: string; scheme: string; schemeURI?: string; language?: string; classificationCode?: string }[];
    freeKeywords?: string[];
    gemetKeywords?: { id: string; path: string; text: string; scheme: string; schemeURI?: string; language?: string; classificationCode?: string }[];
    coverages?: SpatialTemporalCoverageEntry[];
    relatedWorks?: RelatedIdentifier[];
    relatedItems?: Array<Record<string, unknown>>;
    fundingReferences?: FundingReferenceEntry[];
    mslLaboratories?: MSLLaboratory[];
    instruments?: InstrumentSelection[];
    activeRelationTypes?: string[];
    activeIdentifierTypes?: string[];
    initialDatacenters?: number[];
    availableDatacenters?: { id: number; name: string }[];
}

export default function Editor({
    maxTitles,
    maxLicenses,
    googleMapsApiKey,
    doi = '',
    year = '',
    version = '',
    language = '',
    resourceType = '',
    titles = [],
    initialLicenses = [],
    initialRawRights = [],
    resourceId,
    authors = [],
    contributors = [],
    descriptions = [],
    dates = [],
    gcmdKeywords = [],
    freeKeywords = [],
    gemetKeywords = [],
    coverages = [],
    relatedWorks = [],
    relatedItems = [],
    fundingReferences = [],
    mslLaboratories = [],
    instruments = [],
    activeRelationTypes,
    activeIdentifierTypes,
    initialDatacenters = [],
    availableDatacenters = [],
}: EditorProps) {
    const [resourceTypes, setResourceTypes] = useState<ResourceType[] | null>(null);
    const [titleTypes, setTitleTypes] = useState<TitleType[] | null>(null);
    const [dateTypes, setDateTypes] = useState<DateType[] | null>(null);
    const [descriptionTypes, setDescriptionTypes] = useState<DescriptionType[] | null>(null);
    const [licenses, setLicenses] = useState<License[] | null>(null);
    const [languages, setLanguages] = useState<Language[] | null>(null);
    const [contributorPersonRoles, setContributorPersonRoles] = useState<Role[] | null>(null);
    const [contributorInstitutionRoles, setContributorInstitutionRoles] = useState<Role[] | null>(null);
    const [authorRoles, setAuthorRoles] = useState<Role[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    // Get admin status from Inertia shared data to pass to DataCiteForm
    const { auth } = usePage<SharedData>().props;
    const isUserAdmin = auth.user?.role === 'admin';

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Editor',
            href: editor().url,
        },
    ];

    const loadEditorData = useCallback(async () => {
        setIsLoading(true);
        setError(null);
        setResourceTypes(null);
        setTitleTypes(null);
        setDateTypes(null);
        setDescriptionTypes(null);
        setLicenses(null);
        setLanguages(null);
        setContributorPersonRoles(null);
        setContributorInstitutionRoles(null);
        setAuthorRoles(null);

        // Warmup session and fetch resource types in a single request.
        // This prevents 419 errors on fresh container starts and avoids duplicate requests.
        try {
            const warmupResult: WarmupResponse<ResourceType[]> = await warmupSession<ResourceType[]>();
            const resourceTypesData = !warmupResult.success
                ? await fetch('/api/v1/resource-types/ernie').then((res) => {
                      if (!res.ok) throw new Error('Failed to fetch resource types');
                      return res.json() as Promise<ResourceType[]>;
                  })
                : warmupResult.data;

            if (!warmupResult.success && import.meta.env.DEV) {
                console.warn('[Editor] Session warmup failed - CSRF errors may occur on first form submission');
            }

            setResourceTypes(resourceTypesData);

            const [titleRes, dateRes, descTypeRes, licenseRes, languageRes, contributorPersonRes, contributorInstitutionRes, authorRolesRes] = await Promise.all([
                fetch('/api/v1/title-types/ernie'),
                fetch('/api/v1/date-types/ernie'),
                fetch('/api/v1/description-types/ernie'),
                fetch('/api/v1/licenses/ernie'),
                fetch('/api/v1/languages/ernie'),
                fetch('/api/v1/roles/contributor-persons/ernie'),
                fetch('/api/v1/roles/contributor-institutions/ernie'),
                fetch('/api/v1/roles/authors/ernie'),
            ]);

            if (
                !titleRes.ok ||
                !dateRes.ok ||
                !descTypeRes.ok ||
                !licenseRes.ok ||
                !languageRes.ok ||
                !contributorPersonRes.ok ||
                !contributorInstitutionRes.ok ||
                !authorRolesRes.ok
            ) {
                throw new Error('Network error');
            }

            const [tData, dData, descData, lData, langData, contributorPersonData, contributorInstitutionData, authorRoleData] = await Promise.all([
                titleRes.json() as Promise<TitleType[]>,
                dateRes.json() as Promise<DateType[]>,
                descTypeRes.json() as Promise<DescriptionType[]>,
                licenseRes.json() as Promise<License[]>,
                languageRes.json() as Promise<Language[]>,
                contributorPersonRes.json() as Promise<Role[]>,
                contributorInstitutionRes.json() as Promise<Role[]>,
                authorRolesRes.json() as Promise<Role[]>,
            ]);

            setTitleTypes(tData);
            setDateTypes(dData);
            setDescriptionTypes(descData);
            setLicenses(lData);
            setLanguages(langData);
            setContributorPersonRoles(contributorPersonData);
            setContributorInstitutionRoles(contributorInstitutionData);
            setAuthorRoles(authorRoleData);
        } catch (err) {
            console.error('[Editor] Failed to load editor data:', err);
            setError('Unable to load the editor workspace. Check your connection and try again.');
        } finally {
            setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        void loadEditorData();
    }, [loadEditorData]);

    const isEditorReady =
        resourceTypes !== null &&
        titleTypes !== null &&
        dateTypes !== null &&
        descriptionTypes !== null &&
        licenses !== null &&
        languages !== null &&
        contributorPersonRoles !== null &&
        contributorInstitutionRoles !== null &&
        authorRoles !== null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editor" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4" aria-busy={isLoading && !isEditorReady}>
                {error && (
                    <Alert variant="destructive" className="max-w-3xl" data-testid="editor-error-state">
                        <AlertTitle>Editor data unavailable</AlertTitle>
                        <AlertDescription>
                            <p>{error}</p>
                            <div className="mt-4">
                                <Button type="button" variant="outline" onClick={() => void loadEditorData()}>
                                    Retry loading editor data
                                </Button>
                            </div>
                        </AlertDescription>
                    </Alert>
                )}

                {isLoading && !error && (
                    <div data-testid="editor-loading-state" role="status" aria-live="polite" className="grid gap-4">
                        <div className="space-y-1">
                            <p className="text-sm font-medium text-foreground">Loading editor workspace</p>
                            <p className="text-sm text-muted-foreground">
                                Loading resource types, title types, description types, date types, licenses, languages, and role options...
                            </p>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
                            <div className="space-y-4 rounded-2xl border bg-card p-6 shadow-sm">
                                <Skeleton className="h-6 w-48" />
                                <Skeleton className="h-10 w-full" />
                                <Skeleton className="h-10 w-full" />
                                <Skeleton className="h-10 w-3/4" />
                                <Skeleton className="h-40 w-full" />
                            </div>
                            <div className="space-y-4 rounded-2xl border bg-card p-6 shadow-sm">
                                <Skeleton className="h-6 w-32" />
                                <Skeleton className="h-24 w-full" />
                                <Skeleton className="h-24 w-full" />
                                <Skeleton className="h-12 w-full" />
                            </div>
                        </div>
                    </div>
                )}

                {isEditorReady && (
                    <DataCiteForm
                        resourceTypes={resourceTypes}
                        titleTypes={titleTypes}
                        dateTypes={dateTypes}
                        descriptionTypes={descriptionTypes}
                        licenses={licenses}
                        languages={languages}
                        contributorPersonRoles={contributorPersonRoles}
                        contributorInstitutionRoles={contributorInstitutionRoles}
                        authorRoles={authorRoles}
                        maxTitles={maxTitles}
                        maxLicenses={maxLicenses}
                        googleMapsApiKey={googleMapsApiKey}
                        initialDoi={doi}
                        initialYear={year}
                        initialVersion={version}
                        initialLanguage={language}
                        initialResourceType={resourceType}
                        initialTitles={titles}
                        initialLicenses={initialLicenses}
                        initialRawRights={initialRawRights}
                        initialResourceId={resourceId}
                        initialAuthors={authors}
                        initialContributors={contributors}
                        initialDescriptions={descriptions}
                        initialDates={dates}
                        initialGcmdKeywords={gcmdKeywords}
                        initialFreeKeywords={freeKeywords}
                        initialGemetKeywords={gemetKeywords}
                        initialSpatialTemporalCoverages={coverages}
                        initialRelatedWorks={relatedWorks}
                        initialRelatedItems={relatedItems}
                        initialFundingReferences={fundingReferences}
                        initialMslLaboratories={mslLaboratories}
                        initialInstruments={instruments}
                        isUserAdmin={isUserAdmin}
                        activeRelationTypes={activeRelationTypes}
                        activeIdentifierTypes={activeIdentifierTypes}
                        initialDatacenters={initialDatacenters}
                        availableDatacenters={availableDatacenters}
                    />
                )}
            </div>
        </AppLayout>
    );
}
