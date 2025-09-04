import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Documentation',
            href: '/docs',
        },
];

export default function Docs() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documentation" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Collapsible className="w-full rounded-lg border">
                    <CollapsibleTrigger className="group flex w-full items-center justify-between p-4 text-left font-medium">
                        For Admins
                        <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="prose max-w-none space-y-2 p-4 pt-0 dark:prose-invert">
                        <p>To create a new user via the console, run:</p>
                        <pre>
                            <code>
                                php artisan add-user &lt;name&gt; &lt;email&gt; &lt;password&gt;
                            </code>
                        </pre>
                        <p>
                            Replace the placeholders with the new user's name, email address, and
                            password.
                        </p>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </AppLayout>
    );
}
