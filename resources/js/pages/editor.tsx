import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import DataCiteForm, {
    type EditorLandingPageSummary,
    type InitialAuthor,
    type InitialContributor,
    type RawRightsInput,
} from '@/components/curation/datacite-form';
import { type FundingReferenceEntry } from '@/components/curation/fields/funding-reference';
import { type SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';
import { EditorLoadingModal } from '@/components/editor/editor-loading-modal';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useEditorLoadTimeline } from '@/hooks/use-editor-load-timeline';
import { useEditorSlowLoadReporter } from '@/hooks/use-editor-slow-load-reporter';
import AppLayout from '@/layouts/app-layout';
import { clearEditorLoadTimeline, EDITOR_CLIENT_READY_PROGRESS, EDITOR_RESOURCE_TYPES_PROGRESS } from '@/lib/editor-load';
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
import type { EditorClientLoadStage, EditorLoadContext } from '@/types/editor-load';

interface EditorProps {
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
    publicStatus?: 'draft' | 'curation' | 'review' | 'published';
    landingPage?: EditorLandingPageSummary | null;
    authors?: InitialAuthor[];
    contributors?: InitialContributor[];
    descriptions?: { type: string; description: string; language?: string | null }[];
    dates?: { dateType: string; dateMode?: 'single' | 'range'; startDate: string; endDate: string; dateInformation?: string | null }[];
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
    initialDatacenterId?: number | null;
    availableDatacenters?: { id: number; name: string }[];
    editorLoad?: EditorLoadContext;
}

const CLIENT_VOCABULARY_COUNT = 8;
const CLIENT_VOCABULARIES_MAX_PROGRESS = 99;

async function fetchEditorOption<T>(url: string, onCompleted: () => void): Promise<T> {
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(`Failed to load editor option: ${url}`);
    }

    const data = (await response.json()) as T;
    onCompleted();

    return data;
}

export default function Editor({
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
    publicStatus,
    landingPage = null,
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
    initialDatacenterId = null,
    availableDatacenters = [],
    editorLoad,
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
    const [editorLoadProgress, setEditorLoadProgress] = useState(editorLoad?.serverProgress ?? 0);
    const [editorLoadStage, setEditorLoadStage] = useState<EditorClientLoadStage>('client_resource_types');
    const trackedResourceLoad = editorLoad !== undefined;
    const { message: editorLoadingMessage } = useEditorLoadTimeline(editorLoad?.token ?? null);

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
        if (editorLoad) {
            setEditorLoadProgress(editorLoad.serverProgress);
            setEditorLoadStage('client_resource_types');
        }

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
            setEditorLoadProgress((current) => Math.max(current, EDITOR_RESOURCE_TYPES_PROGRESS));
            setEditorLoadStage('client_vocabularies');

            let completedVocabularies = 0;
            const markVocabularyCompleted = (): void => {
                completedVocabularies += 1;
                const completedShare = completedVocabularies / CLIENT_VOCABULARY_COUNT;
                const nextProgress =
                    EDITOR_RESOURCE_TYPES_PROGRESS + Math.round(completedShare * (CLIENT_VOCABULARIES_MAX_PROGRESS - EDITOR_RESOURCE_TYPES_PROGRESS));
                setEditorLoadProgress((current) => Math.max(current, nextProgress));
            };

            const [tData, dData, descData, lData, langData, contributorPersonData, contributorInstitutionData, authorRoleData] = await Promise.all([
                fetchEditorOption<TitleType[]>('/api/v1/title-types/ernie', markVocabularyCompleted),
                fetchEditorOption<DateType[]>('/api/v1/date-types/ernie', markVocabularyCompleted),
                fetchEditorOption<DescriptionType[]>('/api/v1/description-types/ernie', markVocabularyCompleted),
                fetchEditorOption<License[]>('/api/v1/licenses/ernie', markVocabularyCompleted),
                fetchEditorOption<Language[]>('/api/v1/languages/ernie', markVocabularyCompleted),
                fetchEditorOption<Role[]>('/api/v1/roles/contributor-persons/ernie', markVocabularyCompleted),
                fetchEditorOption<Role[]>('/api/v1/roles/contributor-institutions/ernie', markVocabularyCompleted),
                fetchEditorOption<Role[]>('/api/v1/roles/authors/ernie', markVocabularyCompleted),
            ]);

            setTitleTypes(tData);
            setDateTypes(dData);
            setDescriptionTypes(descData);
            setLicenses(lData);
            setLanguages(langData);
            setContributorPersonRoles(contributorPersonData);
            setContributorInstitutionRoles(contributorInstitutionData);
            setAuthorRoles(authorRoleData);
            setEditorLoadStage('client_ready');
            setEditorLoadProgress(EDITOR_CLIENT_READY_PROGRESS);
        } catch (err) {
            console.error('[Editor] Failed to load editor data:', err);
            setError('Unable to load the editor workspace. Check your connection and try again.');
        } finally {
            setIsLoading(false);
        }
    }, [editorLoad]);

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

    useEditorSlowLoadReporter(editorLoad, trackedResourceLoad && !isEditorReady && error === null, editorLoadStage, editorLoadProgress);

    useEffect(() => {
        if (isEditorReady && editorLoad) {
            clearEditorLoadTimeline(editorLoad.token);
        }
    }, [editorLoad, isEditorReady]);

    const retryTrackedLoad = useCallback((): void => window.location.reload(), []);
    const goBackFromTrackedLoad = useCallback((): void => {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        window.location.assign('/resources');
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editor" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4" aria-busy={isLoading && !isEditorReady}>
                {trackedResourceLoad && !isEditorReady && (
                    <EditorLoadingModal
                        progress={editorLoadProgress}
                        message={editorLoadingMessage}
                        error={error}
                        onRetry={retryTrackedLoad}
                        onGoBack={goBackFromTrackedLoad}
                    />
                )}

                {error && !trackedResourceLoad && (
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

                {isLoading && !error && !trackedResourceLoad && (
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
                        initialPublicStatus={publicStatus}
                        initialLandingPage={landingPage}
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
                        initialDatacenterId={initialDatacenterId}
                        availableDatacenters={availableDatacenters}
                    />
                )}
            </div>
        </AppLayout>
    );
}
