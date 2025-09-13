import AppLayout from '@/layouts/app-layout';
import DataCiteForm from '@/components/curation/datacite-form';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    type BreadcrumbItem,
    type ResourceType,
    type TitleType,
    type License,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Curation',
        href: '/curation',
    },
];

interface CurationProps {
    licenses: License[];
    maxTitles: number;
    maxLicenses: number;
    doi?: string;
    year?: string;
    version?: string;
    language?: string;
    resourceType?: string;
    titles?: { title: string; titleType: string }[];
    initialLicenses?: string[];
}

export default function Curation({
    licenses,
    maxTitles,
    maxLicenses,
    doi = '',
    year = '',
    version = '',
    language = '',
    resourceType = '',
    titles = [],
    initialLicenses = [],
}: CurationProps) {
    const [resourceTypes, setResourceTypes] = useState<ResourceType[] | null>(null);
    const [titleTypes, setTitleTypes] = useState<TitleType[] | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        Promise.all([
            fetch('/api/v1/resource-types/ernie'),
            fetch('/api/v1/title-types/ernie'),
        ])
            .then(async ([resTypes, titleRes]) => {
                if (!resTypes.ok || !titleRes.ok) throw new Error('Network error');
                const [rData, tData] = await Promise.all([
                    resTypes.json() as Promise<ResourceType[]>,
                    titleRes.json() as Promise<TitleType[]>,
                ]);
                setResourceTypes(rData);
                setTitleTypes(tData);
            })
            .catch(() => setError(true));
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation" />
            <div
                className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
                aria-busy={resourceTypes === null || titleTypes === null}
            >
                {error && (
                    <p role="alert" className="text-red-600">
                        Unable to load resource or title types.
                    </p>
                )}
                {resourceTypes === null && titleTypes === null && !error && (
                    <p role="status" aria-live="polite">
                        Loading resource and title types...
                    </p>
                )}
                {resourceTypes && titleTypes && (
                    <DataCiteForm
                        resourceTypes={resourceTypes}
                        titleTypes={titleTypes}
                        licenses={licenses}
                        maxTitles={maxTitles}
                        maxLicenses={maxLicenses}
                        initialDoi={doi}
                        initialYear={year}
                        initialVersion={version}
                        initialLanguage={language}
                        initialResourceType={resourceType}
                        initialTitles={titles}
                        initialLicenses={initialLicenses}
                    />
                )}
            </div>
        </AppLayout>
    );
}
