import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import FontSizeToggle from '@/components/font-size-toggle';
import HeadingSmall from '@/components/heading-small';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { appearance } from '@/routes';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: appearance().url,
    },
];

export default function Appearance() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Appearance settings" description="Update your account's appearance settings" />
                    
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">Theme</legend>
                        <AppearanceTabs />
                    </fieldset>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">Font Size</legend>
                        <FontSizeToggle />
                    </fieldset>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
