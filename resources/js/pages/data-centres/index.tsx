import { Head, Link } from '@inertiajs/react';

import { DataCentreGrid } from '@/components/data-centres/DataCentreGrid';
import { DataCentresEmpty } from '@/components/data-centres/DataCentresEmpty';
import HomeLayout from '@/layouts/home-layout';
import type { DataCentresPageProps } from '@/types/data-centres';

export default function DataCentres({ dataCentres }: DataCentresPageProps) {
    return (
        <HomeLayout page="data-centres">
            <Head title="Data Centres">
                <meta
                    name="description"
                    content="Explore the projects, networks and services publishing research data with GFZ Data Services. Select a data centre to find its publications."
                />
                <link rel="canonical" href="https://dataservices.gfz.de/data-centres" />
            </Head>
            <div className="border-b bg-muted/40 px-5 py-9 sm:px-8 sm:py-12">
                <div className="mx-auto max-w-6xl space-y-5">
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Data Centres</h1>
                    <p className="max-w-4xl text-base leading-8 text-muted-foreground">
                        GFZ Data Services brings together research data and scientific software from institutions, projects, networks and
                        international services. Our data centres group these publications by the communities that produce them.
                    </p>
                    <p className="max-w-4xl text-base leading-8 text-muted-foreground">
                        Select a data centre to explore its publications in the Data Portal. You can then refine your search by keywords, resource
                        type, location or time period.
                    </p>
                    <Link href="/data-centres/description" className="inline-flex min-h-11 items-center font-medium underline underline-offset-4">
                        Read more about our data centres
                    </Link>
                </div>
            </div>
            <div className="mx-auto max-w-6xl px-5 py-9 sm:px-8 sm:py-12">
                {dataCentres.length > 0 ? <DataCentreGrid dataCentres={dataCentres} /> : <DataCentresEmpty />}
            </div>
        </HomeLayout>
    );
}
