import { Head, router } from '@inertiajs/react';
import { ExternalLink, Search } from 'lucide-react';
import type { FormEvent } from 'react';

import { HomeNews } from '@/components/home/HomeNews';
import { ScienceTopicGrid } from '@/components/home/ScienceTopicGrid';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { elmoUrl, homepageLinkGroups, homepageNews, type ScienceTopic } from '@/data/homepage';
import HomeLayout from '@/layouts/home-layout';

export default function Home({ topics }: { topics: ScienceTopic[] }) {
    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const query = String(new FormData(event.currentTarget).get('q') ?? '').trim();
        router.get('/doi-search', query ? { q: query } : {});
    }

    return (
        <HomeLayout>
            <Head title="GFZ Data Services">
                <meta
                    name="description"
                    content="Discover curated geoscience research data, scientific software and samples with GFZ Data Services. Publish metadata and explore data by science topic."
                />
                <link rel="canonical" href="https://dataservices.gfz.de/" />
            </Head>

            <section className="border-b bg-muted/40 px-5 py-9 sm:px-8 sm:py-12" aria-labelledby="home-welcome">
                <div className="mx-auto max-w-6xl">
                    <h2 id="home-welcome" className="mb-5 text-2xl font-semibold tracking-tight sm:text-3xl">
                        Welcome to GFZ Data Services
                    </h2>
                    <p className="mb-7 max-w-5xl text-base leading-8 text-muted-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-4">
                        GFZ Data Services is a{' '}
                        <a href="https://dataservices.gfz.de/portal" target="_blank" rel="noopener noreferrer">
                            research data repository for curated, DOI-referenced data and scientific software
                        </a>{' '}
                        from the geosciences domain, hosted at the{' '}
                        <a href="https://gfz.de" target="_blank" rel="noopener noreferrer">
                            GFZ Helmholtz Centre for Geosciences
                        </a>
                        . Furthermore, GFZ Data Services hosts the{' '}
                        <a href="https://dataservices.gfz.de/igsn-new" target="_blank" rel="noopener noreferrer">
                            GFZ Catalogue
                        </a>{' '}
                        and provides minting services for the{' '}
                        <a href="https://www.igsn.org/" target="_blank" rel="noopener noreferrer">
                            IGSN International Generic Sample Number
                        </a>
                        , and was involved in the development of the{' '}
                        <a href="https://research-infrastructure.gfz.de/en/" target="_blank" rel="noopener noreferrer">
                            GFZ Research Infrastructures
                        </a>{' '}
                        portal.
                    </p>
                    <HomeNews news={homepageNews} />
                </div>
            </section>

            <div className="mx-auto max-w-6xl px-5 py-9 sm:px-8 sm:py-12">
                <div className="mb-12 flex flex-col gap-5 sm:flex-row sm:items-end sm:gap-8">
                    <Button
                        asChild
                        size="lg"
                        className="h-12 shrink-0 bg-[#012965] px-6 tracking-wider text-white ring-offset-background hover:bg-[#012965]/90 focus-visible:ring-[#012965] focus-visible:ring-offset-2 dark:focus-visible:ring-blue-300"
                    >
                        <a href={elmoUrl} target="_blank" rel="noopener noreferrer">
                            SUBMIT METADATA <ExternalLink className="size-4" aria-hidden="true" />
                            <span className="sr-only"> (opens in a new tab)</span>
                        </a>
                    </Button>
                    <form
                        action="/doi-search"
                        method="get"
                        onSubmit={search}
                        role="search"
                        aria-label="Search research data"
                        className="min-w-0 flex-1"
                    >
                        <label htmlFor="home-search" className="mb-2 block text-sm font-medium">
                            Search research data
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="home-search"
                                name="q"
                                type="search"
                                maxLength={500}
                                placeholder="Search for data, datacenters, science keywords"
                                className="h-12 bg-background"
                            />
                            <Button
                                type="submit"
                                size="lg"
                                className="h-12 bg-[#012965] px-4 text-white ring-offset-background hover:bg-[#012965]/90 focus-visible:ring-[#012965] focus-visible:ring-offset-2 dark:focus-visible:ring-blue-300"
                                aria-label="Search"
                            >
                                <Search className="size-5" aria-hidden="true" />
                            </Button>
                        </div>
                    </form>
                </div>
                <section aria-labelledby="home-topics">
                    <h2 id="home-topics" className="mb-8 text-center text-xl font-semibold">
                        Explore by science topic
                    </h2>
                    <ScienceTopicGrid topics={topics} />
                </section>
            </div>

            <div className="border-t bg-muted/50 px-5 py-10 sm:px-8">
                <div className="mx-auto grid max-w-6xl gap-10 md:grid-cols-3">
                    {homepageLinkGroups.map((group) => (
                        <section key={group.title} aria-label={group.title}>
                            <h2 className="mb-4 text-lg font-semibold">{group.title}</h2>
                            <ul className="space-y-3 text-sm leading-6">
                                {group.links.map((link) => (
                                    <li key={link.href}>
                                        <a
                                            href={link.href}
                                            className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                        >
                                            {link.label}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </div>
        </HomeLayout>
    );
}
