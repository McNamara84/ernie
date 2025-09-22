import { withBasePath } from '@/lib/base-path';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Documentation', href: withBasePath('/docs') },
    { title: 'User guide', href: withBasePath('/docs/users') },
];

export default function DocsUsers() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Documentation" />
            <div className="prose max-w-none space-y-4 p-4 pt-0 dark:prose-invert">
                <h2>Add new curators</h2>
                <p>
                    If you need additional accounts for curating research data, please contact the administrator at{' '}
                    <a href="mailto:ehrmann@gfz.de">ehrmann@gfz.de</a>.
                </p>
            </div>
        </AppLayout>
    );
}
