import { Head } from '@inertiajs/react';

import AppLayout from '@/layouts/app-layout';
import { withBasePath } from '@/lib/base-path';
import { type BreadcrumbItem } from '@/types';

interface DocsProps {
    userRole: string;
}

export default function Docs({ userRole }: DocsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Documentation',
            href: withBasePath('/docs'),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentation" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="prose max-w-none dark:prose-invert">
                    <h1>Documentation</h1>
                    <p>
                        Welcome to the ERNIE documentation. This page will be updated with
                        comprehensive guides for curators and administrators.
                    </p>
                    <p>Current user role: <strong>{userRole}</strong></p>
                    
                    <h2>Quick Links</h2>
                    <ul>
                        <li>
                            <a href={withBasePath('/api/v1/doc')} className="underline">
                                API Documentation
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </AppLayout>
    );
}
