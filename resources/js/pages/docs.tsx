import AppLayout from '@/layouts/app-layout';
import { docs } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Documentation',
        href: docs().url,
    },
];

export default function Docs() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentation" />
            <div className="prose max-w-none p-4 dark:prose-invert">
                <h1>Documentation</h1>
                <section>
                    <h2>For Admins</h2>
                    <p>To create a new user via the console, run:</p>
                    <pre>
                        <code>php artisan make:user</code>
                    </pre>
                </section>
            </div>
        </AppLayout>
    );
}
