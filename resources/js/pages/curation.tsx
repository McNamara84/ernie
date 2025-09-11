import AppLayout from '@/layouts/app-layout';
import DataCiteForm from '@/components/curation/datacite-form';
import { Head } from '@inertiajs/react';
import { type BreadcrumbItem, type ResourceType, type TitleType } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Curation',
        href: '/curation',
    },
];

interface CurationProps {
    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
    doi?: string;
}

export default function Curation({ resourceTypes, titleTypes, doi = '' }: CurationProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    initialDoi={doi}
                />
            </div>
        </AppLayout>
    );
}
