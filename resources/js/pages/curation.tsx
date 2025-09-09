import AppLayout from '@/layouts/app-layout';
import DataCiteForm from '@/components/curation/datacite-form';
import { Head } from '@inertiajs/react';
import { curation } from '@/routes';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Curation',
        href: curation().url,
    },
];

export default function Curation() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <DataCiteForm />
            </div>
        </AppLayout>
    );
}
