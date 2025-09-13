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
    titleTypes: TitleType[];
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
    titleTypes,
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
    const [error, setError] = useState(false);

    useEffect(() => {
        fetch('/api/v1/resource-types/ernie')
            .then((res) => {
                if (!res.ok) throw new Error('Network error');
                return res.json();
            })
            .then((data: ResourceType[]) => setResourceTypes(data))
            .catch(() => setError(true));
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4" aria-busy={resourceTypes === null}>
                {error && (
                    <p role="alert" className="text-red-600">
                        Unable to load resource types.
                    </p>
                )}
                {resourceTypes === null && !error && (
                    <p role="status" aria-live="polite">
                        Loading resource types...
                    </p>
                )}
                {resourceTypes && (
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
