import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import DataCiteForm, { type InitialAuthor, type InitialContributor } from '@/components/curation/datacite-form';
import { type FundingReferenceEntry } from '@/components/curation/fields/funding-reference';
import { type SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';
import AppLayout from '@/layouts/app-layout';
import { warmupSession } from '@/lib/session-warmup';
import { editor } from '@/routes';
import {
    type BreadcrumbItem,
    type DateType,
    type Language,
    type License,
    type MSLLaboratory,
    type RelatedIdentifier,
    type ResourceType,
    type Role,
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
    resourceId?: string;
    authors?: InitialAuthor[];
    contributors?: InitialContributor[];
    descriptions?: { type: string; description: string }[];
    dates?: { dateType: string; startDate: string; endDate: string }[];
    gcmdKeywords?: { id: string; path: string; text: string; scheme: string; schemeURI?: string; language?: string }[];
    freeKeywords?: string[];
    coverages?: SpatialTemporalCoverageEntry[];
    relatedWorks?: RelatedIdentifier[];
    fundingReferences?: FundingReferenceEntry[];
    mslLaboratories?: MSLLaboratory[];
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
    resourceId,
    authors = [],
    contributors = [],
    descriptions = [],
    dates = [],
    gcmdKeywords = [],
    freeKeywords = [],
    coverages = [],
    relatedWorks = [],
    fundingReferences = [],
    mslLaboratories = [],
}: EditorProps) {
    const [resourceTypes, setResourceTypes] = useState<ResourceType[] | null>(null);
    const [titleTypes, setTitleTypes] = useState<TitleType[] | null>(null);
    const [dateTypes, setDateTypes] = useState<DateType[] | null>(null);
    const [licenses, setLicenses] = useState<License[] | null>(null);
    const [languages, setLanguages] = useState<Language[] | null>(null);
    const [contributorPersonRoles, setContributorPersonRoles] = useState<Role[] | null>(null);
    const [contributorInstitutionRoles, setContributorInstitutionRoles] = useState<Role[] | null>(null);
    const [authorRoles, setAuthorRoles] = useState<Role[] | null>(null);
    const [error, setError] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Editor',
            href: editor().url,
        },
    ];

    useEffect(() => {
        // Warmup session first to ensure CSRF token is initialized
        // This prevents 419 errors on fresh container starts
        warmupSession().then(() => {
            Promise.all([
                fetch('/api/v1/resource-types/ernie'),
                fetch('/api/v1/title-types/ernie'),
                fetch('/api/v1/date-types/ernie'),
                fetch('/api/v1/licenses/ernie'),
                fetch('/api/v1/languages/ernie'),
                fetch('/api/v1/roles/contributor-persons/ernie'),
                fetch('/api/v1/roles/contributor-institutions/ernie'),
                fetch('/api/v1/roles/authors/ernie'),
            ])
                .then(
                    async ([
                        resTypes,
                        titleRes,
                        dateRes,
                        licenseRes,
                        languageRes,
                        contributorPersonRes,
                        contributorInstitutionRes,
                        authorRolesRes,
                    ]) => {
                        if (
                            !resTypes.ok ||
                            !titleRes.ok ||
                            !dateRes.ok ||
                            !licenseRes.ok ||
                            !languageRes.ok ||
                            !contributorPersonRes.ok ||
                            !contributorInstitutionRes.ok ||
                            !authorRolesRes.ok
                        ) {
                            throw new Error('Network error');
                        }
                        const [rData, tData, dData, lData, langData, contributorPersonData, contributorInstitutionData, authorRoleData] =
                            await Promise.all([
                                resTypes.json() as Promise<ResourceType[]>,
                                titleRes.json() as Promise<TitleType[]>,
                                dateRes.json() as Promise<DateType[]>,
                                licenseRes.json() as Promise<License[]>,
                                languageRes.json() as Promise<Language[]>,
                                contributorPersonRes.json() as Promise<Role[]>,
                                contributorInstitutionRes.json() as Promise<Role[]>,
                                authorRolesRes.json() as Promise<Role[]>,
                            ]);
                        setResourceTypes(rData);
                        setTitleTypes(tData);
                        setDateTypes(dData);
                        setLicenses(lData);
                        setLanguages(langData);
                        setContributorPersonRoles(contributorPersonData);
                        setContributorInstitutionRoles(contributorInstitutionData);
                        setAuthorRoles(authorRoleData);
                    },
                )
                .catch(() => setError(true));
        });
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editor" />
            <div
                className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
                aria-busy={
                    resourceTypes === null ||
                    titleTypes === null ||
                    dateTypes === null ||
                    licenses === null ||
                    languages === null ||
                    contributorPersonRoles === null ||
                    contributorInstitutionRoles === null ||
                    authorRoles === null
                }
            >
                {error && (
                    <p role="alert" className="text-red-600">
                        Unable to load resource or title types, licenses, languages, or role options.
                    </p>
                )}
                {(resourceTypes === null ||
                    titleTypes === null ||
                    dateTypes === null ||
                    licenses === null ||
                    languages === null ||
                    contributorPersonRoles === null ||
                    contributorInstitutionRoles === null ||
                    authorRoles === null) &&
                    !error && (
                        <p role="status" aria-live="polite">
                            Loading resource and title types, licenses, languages, and role options...
                        </p>
                    )}
                {resourceTypes &&
                    titleTypes &&
                    dateTypes &&
                    licenses &&
                    languages &&
                    contributorPersonRoles &&
                    contributorInstitutionRoles &&
                    authorRoles && (
                        <DataCiteForm
                            resourceTypes={resourceTypes}
                            titleTypes={titleTypes}
                            dateTypes={dateTypes}
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
                            initialResourceId={resourceId}
                            initialAuthors={authors}
                            initialContributors={contributors}
                            initialDescriptions={descriptions}
                            initialDates={dates}
                            initialGcmdKeywords={gcmdKeywords}
                            initialFreeKeywords={freeKeywords}
                            initialSpatialTemporalCoverages={coverages}
                            initialRelatedWorks={relatedWorks}
                            initialFundingReferences={fundingReferences}
                            initialMslLaboratories={mslLaboratories}
                        />
                    )}
            </div>
        </AppLayout>
    );
}
