import AppLayout from '@/layouts/app-layout';
import DataCiteForm from '@/components/curation/datacite-form';
import { Head } from '@inertiajs/react';
import { type BreadcrumbItem, type ResourceType, type TitleType, type License } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Curation',
        href: '/curation',
    },
];

interface CurationProps {
    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
    licenses: License[];
    doi?: string;
    year?: string;
    version?: string;
    language?: string;
    resourceType?: string;
    titles?: { title: string; titleType: string }[];
    initialLicenses?: string[];
}

export default function Curation({
    resourceTypes,
    titleTypes,
    licenses,
    doi = '',
    year = '',
    version = '',
    language = '',
    resourceType = '',
    titles = [],
    initialLicenses = [],
}: CurationProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    initialDoi={doi}
                    initialYear={year}
                    initialVersion={version}
                    initialLanguage={language}
                    initialResourceType={resourceType}
                    initialTitles={titles}
                    initialLicenses={initialLicenses}
                />
            </div>
        </AppLayout>
    );
}
