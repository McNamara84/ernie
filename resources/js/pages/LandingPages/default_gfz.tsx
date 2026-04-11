import { Head, usePage } from '@inertiajs/react';

import { Toaster } from '@/components/ui/sonner';
import type { LandingPageConfig, LandingPageResource } from '@/types/landing-page';

import { AbstractSection } from './components/AbstractSection';
import { ContactSection } from './components/ContactSection';
import { FilesSection } from './components/FilesSection';
import { LocationSection } from './components/LocationSection';
import { ModelDescriptionSection } from './components/ModelDescriptionSection';
import { RelatedWorkSection } from './components/RelatedWorkSection';
import { ResourceHero } from './components/ResourceHero';
import { useSystemDarkMode } from './hooks/useSystemDarkMode';
import { buildCitation } from './lib/buildCitation';

/**
 * Props passed to landing page templates via Inertia
 *
 * Uses centralized types from @/types/landing-page.ts
 *
 * Note: The index signature is required because Inertia's usePage<T>() generic
 * expects T to be assignable to PageProps, which includes dynamic properties.
 * This is a known Inertia.js pattern - see SharedData in @/types for the same approach.
 */
interface DefaultGfzTemplatePageProps {
    resource: LandingPageResource;
    landingPage: LandingPageConfig | null;
    isPreview: boolean;
    schemaOrgJsonLd?: Record<string, unknown>;
    /** Inertia PageProps requires index signature for dynamic SSR props */
    [key: string]: unknown;
}

export default function DefaultGfzTemplate() {
    const { resource, landingPage, isPreview, schemaOrgJsonLd } = usePage<DefaultGfzTemplatePageProps>().props;
    const isDark = useSystemDarkMode();

    // Extract data for ResourceHero
    const resourceType = resource.resource_type?.name || 'Other';
    const status = isPreview ? 'preview' : landingPage?.status || 'published';
    const mainTitle = resource.titles?.find((t) => !t.title_type || t.title_type === 'MainTitle')?.title || 'Untitled';
    const subtitle = resource.titles?.find((t) => t.title_type === 'Subtitle')?.title;
    const citation = buildCitation(resource);

    return (
        <>
            {schemaOrgJsonLd && (
                <Head>
                    <script type="application/ld+json">{JSON.stringify(schemaOrgJsonLd)}</script>
                </Head>
            )}
            <div className="min-h-screen bg-gfz-primary pt-6 dark:bg-gray-950">
                {/* Skip Navigation Link */}
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-gfz-primary focus:shadow-lg"
                >
                    Skip to main content
                </a>

                {isPreview && (
                    <div role="status" className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">
                        Preview Mode
                    </div>
                )}

                <div className="mx-auto max-w-7xl rounded-xl bg-white dark:bg-gray-900">
                    <header className="px-4 py-2">
                        <div className="mb-1 flex items-center justify-end gap-3">
                            <a href="/legal-notice" className="text-xs text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-gray-200">
                                Legal Notice
                            </a>
                            <span className="text-xs text-gray-300 dark:text-gray-600" aria-hidden="true">|</span>
                            <a
                                href="https://dataservices.gfz.de/web/about-us/data-protection"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                Data Protection
                            </a>
                        </div>
                        <div className="flex justify-center">
                            <img src="/images/gfz-ds-logo.png" alt="GFZ Data Services" className="h-24 dark:brightness-200 dark:invert" />
                        </div>
                    </header>

                    <main id="main-content">
                        {/* Hero Section - Full Width */}
                        <ResourceHero resourceType={resourceType} status={status} mainTitle={mainTitle} subtitle={subtitle} citation={citation} />

                        {/* Two Column Layout — Abstract first on mobile for logical reading order */}
                        <div className="mx-8 mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                            {/* Right Column (Abstract, Location) — show first on mobile */}
                            <div className="order-1 space-y-6 lg:order-2 lg:col-span-2">
                                <AbstractSection
                                    descriptions={resource.descriptions || []}
                                    creators={resource.creators || []}
                                    contributors={resource.contributors || []}
                                    fundingReferences={resource.funding_references || []}
                                    subjects={resource.subjects || []}
                                    resourceId={resource.id}
                                    jsonLdExportUrl={landingPage?.public_url ? `${landingPage.public_url}/jsonld` : undefined}
                                />
                                <LocationSection geoLocations={resource.geo_locations || []} isDark={isDark} />
                            </div>

                            {/* Left Column (Files, Contact, Related) — show second on mobile */}
                            <div className="order-2 space-y-6 lg:order-1 lg:col-span-1">
                                <FilesSection
                                    downloadUrl={landingPage?.ftp_url}
                                    downloadFiles={landingPage?.files}
                                    licenses={resource.licenses || []}
                                    contactPersons={resource.contact_persons || []}
                                    datasetTitle={mainTitle}
                                    additionalLinks={landingPage?.links}
                                />
                                <ContactSection contactPersons={resource.contact_persons || []} datasetTitle={mainTitle} />
                                <ModelDescriptionSection relatedIdentifiers={resource.related_identifiers || []} />
                                <RelatedWorkSection relatedIdentifiers={resource.related_identifiers || []} resource={resource} />
                            </div>
                        </div>
                    </main>

                    <footer className="border-t border-gray-300 px-4 py-6 dark:border-gray-700">
                        <div className="flex items-center justify-between">
                            <a href="https://www.gfz.de" target="_blank" rel="noopener noreferrer">
                                <img src="/images/gfz-logo-en.gif" alt="GFZ" className="h-12 dark:brightness-200 dark:invert" />
                            </a>
                            <a href="https://www.helmholtz.de" target="_blank" rel="noopener noreferrer">
                                <img src="/images/helmholtz-logo-blue.png" alt="Helmholtz" className="h-8 dark:brightness-200 dark:invert" />
                            </a>
                        </div>
                    </footer>
                </div>

                <Toaster position="bottom-right" richColors theme={isDark ? 'dark' : 'light'} />
            </div>
        </>
    );
}
