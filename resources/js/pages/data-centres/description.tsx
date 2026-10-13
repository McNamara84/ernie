import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { DataCentresEmpty } from '@/components/data-centres/DataCentresEmpty';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import HomeLayout from '@/layouts/home-layout';
import { buildDataCentreSearchUrl } from '@/lib/portal-filter-url';
import type { DataCentresPageProps } from '@/types/data-centres';

export default function DataCentreDescription({ dataCentres }: DataCentresPageProps) {
    const [openCentres, setOpenCentres] = useState<string[]>([]);

    useEffect(() => {
        let frame: number | undefined;
        const revealFragment = () => {
            if (frame !== undefined) cancelAnimationFrame(frame);
            let slug: string;
            try {
                slug = decodeURIComponent(window.location.hash.slice(1));
            } catch {
                return;
            }
            if (!dataCentres.some((centre) => centre.slug === slug)) return;

            setOpenCentres((current) => (current.includes(slug) ? current : [...current, slug]));
            // Wait until the controlled panel has opened before scrolling/focusing.
            frame = requestAnimationFrame(() => {
                const item = document.getElementById(slug);
                item?.scrollIntoView({ block: 'start', behavior: 'instant' });
                item?.querySelector<HTMLButtonElement>('[data-slot="accordion-trigger"]')?.focus({ preventScroll: true });
            });
        };

        revealFragment();
        window.addEventListener('hashchange', revealFragment);
        window.addEventListener('popstate', revealFragment);
        // Firefox restores native focus after a reload; reveal once more after
        // page presentation, including restoration from the back/forward cache.
        window.addEventListener('pageshow', revealFragment);
        const removeNavigate = router.on('navigate', revealFragment);
        return () => {
            if (frame !== undefined) cancelAnimationFrame(frame);
            window.removeEventListener('hashchange', revealFragment);
            window.removeEventListener('popstate', revealFragment);
            window.removeEventListener('pageshow', revealFragment);
            removeNavigate();
        };
    }, [dataCentres]);

    return (
        <HomeLayout page="data-centres-description">
            <Head title="Data Centre Descriptions">
                <meta
                    name="description"
                    content="Learn about the data centres represented in GFZ Data Services and explore publications from their projects, networks and international services."
                />
                <link rel="canonical" href="https://dataservices.gfz.de/data-centres/description" />
            </Head>
            <div className="border-b bg-muted/40 px-5 py-9 [overflow-wrap:anywhere] sm:px-8 sm:py-12">
                <div className="mx-auto max-w-6xl space-y-5">
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Data Centre Descriptions</h1>
                    <p className="max-w-4xl text-base leading-8 text-muted-foreground">
                        Discover the communities behind our data publications. Open a data centre to learn more about its work and find its
                        publications.
                    </p>
                    <Link href="/data-centres" className="inline-flex min-h-11 items-center font-medium underline underline-offset-4">
                        Back to data centres
                    </Link>
                </div>
            </div>
            <div className="mx-auto max-w-6xl px-5 py-9 [overflow-wrap:anywhere] sm:px-8 sm:py-12">
                {dataCentres.length === 0 ? (
                    <DataCentresEmpty />
                ) : (
                    <Accordion type="multiple" value={openCentres} onValueChange={setOpenCentres}>
                        {dataCentres.map((centre) => (
                            <AccordionItem key={centre.slug} value={centre.slug} id={centre.slug} className="scroll-mt-6">
                                <AccordionTrigger headingLevel={2} className="min-h-14 min-w-0 text-base sm:text-lg">
                                    {centre.displayName}
                                </AccordionTrigger>
                                <AccordionContent className="space-y-5 text-base leading-8 motion-reduce:animate-none">
                                    {centre.logo && (
                                        <img
                                            src={centre.logo.src}
                                            width={centre.logo.width}
                                            height={centre.logo.height}
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                            className="h-28 w-48 rounded bg-white object-contain p-2"
                                        />
                                    )}
                                    {centre.description.map((paragraph, index) => (
                                        <p key={index} className="max-w-4xl text-muted-foreground">
                                            {paragraph}
                                        </p>
                                    ))}
                                    <div className="flex flex-wrap items-center gap-x-7 gap-y-3">
                                        <Link
                                            href={buildDataCentreSearchUrl(centre.datacenterName)}
                                            className="inline-flex min-h-11 items-center font-semibold underline underline-offset-4"
                                            aria-label={`View publications: ${centre.displayName}`}
                                        >
                                            View publications
                                        </Link>
                                        {centre.links.map((link) => (
                                            <a
                                                key={link.href}
                                                href={link.href}
                                                className="inline-flex min-h-11 items-center underline underline-offset-4"
                                            >
                                                {link.label}
                                            </a>
                                        ))}
                                        <a
                                            href={`#${centre.slug}`}
                                            className="inline-flex min-h-11 items-center underline underline-offset-4"
                                            aria-label={`Direct link: ${centre.displayName}`}
                                        >
                                            Direct link
                                        </a>
                                    </div>
                                </AccordionContent>
                            </AccordionItem>
                        ))}
                    </Accordion>
                )}
            </div>
        </HomeLayout>
    );
}
