import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import HomeLayout from '@/layouts/home-layout';

export default function Find() {
    return (
        <HomeLayout page="find">
            <Head title="Find">
                <meta
                    name="description"
                    content="Find research data, scientific software, physical samples and research infrastructures with GFZ Data Services. Explore the Data Portal and IGSN Portal."
                />
                <link rel="canonical" href="https://dataservices.gfz.de/find" />
            </Head>

            <div className="border-b bg-muted/40 px-5 py-9 sm:px-8 sm:py-12">
                <div className="mx-auto max-w-6xl">
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Find</h1>
                </div>
            </div>

            <div className="mx-auto max-w-6xl divide-y px-5 break-words sm:px-8">
                <section aria-labelledby="find-data-portal" className="grid items-center gap-8 py-9 sm:py-12 lg:grid-cols-2 lg:gap-10">
                    <Link href="/doi-search" className="block min-w-0">
                        <img
                            src="/images/find/data-portal.png"
                            alt="Data Portal search interface with filters, research publications and a map"
                            width={1440}
                            height={900}
                            className="h-auto w-full border"
                            fetchPriority="high"
                        />
                    </Link>
                    <div className="min-w-0 space-y-5">
                        <h2 id="find-data-portal" className="text-2xl font-semibold tracking-tight">
                            <Link href="/doi-search" className="underline-offset-4 hover:underline">
                                Data Portal
                            </Link>
                        </h2>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground">
                            Use the Data Portal to discover research data and scientific software published through GFZ Data Services, the geoscience
                            repository hosted at the GFZ Helmholtz Centre for Geosciences in Potsdam, Germany.
                        </p>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground">
                            Search by text or keywords and narrow the results by data centre, resource type, location or time period. Explore
                            georeferenced records on the map and open a publication&apos;s landing page for its description, citation and access
                            information.
                        </p>
                        <Button
                            asChild
                            className="h-auto min-h-11 max-w-full bg-gfz-primary px-5 py-3 whitespace-normal text-gfz-primary-foreground hover:bg-gfz-primary/90"
                        >
                            <Link href="/doi-search">Explore the Data Portal</Link>
                        </Button>
                    </div>
                </section>

                <section aria-labelledby="find-data-centres" className="grid items-center gap-8 py-9 sm:py-12 lg:grid-cols-2 lg:gap-10">
                    <a href="https://dataservices.gfz-potsdam.de/web/find/data-centres" className="block min-w-0 lg:order-last">
                        <img
                            src="/images/find/data-centres.png"
                            alt="Data Centres overview with logos of participating projects and networks"
                            width={620}
                            height={321}
                            className="h-auto w-full"
                            loading="lazy"
                            decoding="async"
                        />
                    </a>
                    <div className="min-w-0 space-y-5">
                        <h2 id="find-data-centres" className="text-2xl font-semibold tracking-tight">
                            <a href="https://dataservices.gfz-potsdam.de/web/find/data-centres" className="underline-offset-4 hover:underline">
                                Data Centres
                            </a>
                        </h2>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4">
                            During the past years we have developed several{' '}
                            <a
                                href="https://dataservices.gfz-potsdam.de/web/support/glossary"
                                title="A DOI is an online reference assigned to a digital resource (e.g. an article in a journal or research data) to give it a unique and permanent reference on the Internet."
                            >
                                DOI
                            </a>{' '}
                            minting services for larger projects and international services and networks. Our Data Centres organise data publications
                            according to their projects, networks or services affinity and may have project-specific landing page designs.
                        </p>
                    </div>
                </section>

                <section aria-labelledby="find-infrastructures" className="grid items-center gap-8 py-9 sm:py-12 lg:grid-cols-2 lg:gap-10">
                    <a href="https://research-infrastructure.gfz.de/en/" target="_blank" rel="noopener noreferrer" className="block min-w-0">
                        <img
                            src="/images/find/research-infrastructures.png"
                            alt="GFZ Research Infrastructures overview with instruments, laboratories and data services"
                            width={620}
                            height={318}
                            className="h-auto w-full"
                            loading="lazy"
                            decoding="async"
                        />
                    </a>
                    <div className="min-w-0 space-y-5">
                        <h2 id="find-infrastructures" className="text-2xl font-semibold tracking-tight">
                            <a
                                href="https://research-infrastructure.gfz.de/en/"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline-offset-4 hover:underline"
                            >
                                Research Infrastructures at GFZ
                            </a>
                        </h2>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4">
                            The research infrastructure at GFZ comprises satellite systems, global station networks, and regional observatories, as
                            well as instrument networks, laboratories, instrument pools and data systems. As a Helmholtz centre, GFZ fulfils an
                            important role in providing infrastructure, data, information, instrument systems and networks. We classify the
                            instruments and services of our research infrastructure that are available to the entire international scientific
                            community and which are subject to special terms of use as Modular Earth Science Infrastructure (
                            <a
                                href="https://www.gfz.de/en/research/topics/our-research-program/research-infrastructures/mesi"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                MESI
                            </a>
                            ).
                        </p>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4">
                            <a href="mailto:ResearchInfrastructure@GFZ" target="_blank" rel="noopener noreferrer">
                                <strong>ResearchInfrastructure@GFZ</strong>
                            </a>{' '}
                            is the central discovery portal for research infrastructures and data at GFZ. It enables structured searches across
                            disciplines, product categories (data, services and software), persons and GFZ sections, and provides basic information
                            with links to further information on the GFZ websites and data access.
                        </p>
                    </div>
                </section>

                <section aria-labelledby="find-igsn-portal" className="grid items-center gap-8 py-9 sm:py-12 lg:grid-cols-2 lg:gap-10">
                    <Link href="/igsn-search" className="block min-w-0 lg:order-last">
                        <img
                            src="/images/find/igsn-portal.png"
                            alt="IGSN Portal search interface with sample filters, registered samples and a map"
                            width={1440}
                            height={900}
                            className="h-auto w-full border"
                            loading="lazy"
                            decoding="async"
                        />
                    </Link>
                    <div className="min-w-0 space-y-5">
                        <h2 id="find-igsn-portal" className="text-2xl font-semibold tracking-tight">
                            <Link href="/igsn-search" className="underline-offset-4 hover:underline">
                                IGSN Portal
                            </Link>
                        </h2>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground">
                            The IGSN Portal helps you discover physical samples registered through GFZ Data Services. An International Generic Sample
                            Number (IGSN) gives a sample a persistent identity, allowing it to be cited and connected with research data and
                            publications.
                        </p>
                        <p className="max-w-5xl text-base leading-8 text-muted-foreground">
                            Search for sample identifiers, local sample names or keywords, and refine the results by sample type, material or
                            geological classification. Use the map to explore samples with location information, then open a sample&apos;s landing
                            page to view its metadata.
                        </p>
                        <Button
                            asChild
                            className="h-auto min-h-11 max-w-full bg-gfz-primary px-5 py-3 whitespace-normal text-gfz-primary-foreground hover:bg-gfz-primary/90"
                        >
                            <Link href="/igsn-search">Explore the IGSN Portal</Link>
                        </Button>
                    </div>
                </section>
            </div>
        </HomeLayout>
    );
}
