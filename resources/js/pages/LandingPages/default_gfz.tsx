import { Head, usePage } from '@inertiajs/react';
import { type ReactNode, useMemo } from 'react';

import type { LandingPageConfig, LandingPageResource, LeftColumnSection, RightColumnSection, SectionOrder } from '@/types/landing-page';

import { AbstractSection } from './components/AbstractSection';
import { BackToTopButton } from './components/BackToTopButton';
import { ContactSection } from './components/ContactSection';
import { DarkModeImage } from './components/DarkModeImage';
import { FilesSection } from './components/FilesSection';
import { LandingPageToaster } from './components/LandingPageToaster';
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
    sectionOrder?: SectionOrder | null;
    customLogoUrl?: string | null;
    /** Inertia PageProps requires index signature for dynamic SSR props */
    [key: string]: unknown;
}

/** Default section orders matching the original layout */
const DEFAULT_RIGHT_ORDER: RightColumnSection[] = ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'location'];
const DEFAULT_LEFT_ORDER: LeftColumnSection[] = ['files', 'contact', 'model_description', 'related_work'];

export default function DefaultGfzTemplate() {
    const { resource, landingPage, isPreview, schemaOrgJsonLd, sectionOrder, customLogoUrl } = usePage<DefaultGfzTemplatePageProps>().props;
    const isDark = useSystemDarkMode();

    // Extract data for ResourceHero
    const resourceType = resource.resource_type?.name || 'Other';
    const status = isPreview ? 'preview' : landingPage?.status || 'published';
    const mainTitle = resource.titles?.find((t) => !t.title_type || t.title_type === 'MainTitle')?.title || 'Untitled';
    const subtitle = resource.titles?.find((t) => t.title_type === 'Subtitle')?.title;
    const citation = buildCitation(resource);

    // Resolve section orders (custom template overrides or defaults)
    const rightOrder = sectionOrder?.rightColumn ?? DEFAULT_RIGHT_ORDER;
    const leftOrder = sectionOrder?.leftColumn ?? DEFAULT_LEFT_ORDER;

    // Logo: custom template logo or default GFZ logo
    const logoSrc = customLogoUrl ?? '/images/gfz-ds-logo.png';

    // Section registry: map section keys to React elements
    const rightSectionRegistry = useMemo((): Record<RightColumnSection, ReactNode> => {
        const jsonLdExportUrl = landingPage?.public_url ? `${landingPage.public_url}/jsonld` : undefined;
        return {
            descriptions: (
                <AbstractSection
                    key="descriptions"
                    descriptions={resource.descriptions || []}
                    creators={resource.creators || []}
                    contributors={resource.contributors || []}
                    fundingReferences={resource.funding_references || []}
                    subjects={resource.subjects || []}
                    resourceId={resource.id}
                    jsonLdExportUrl={jsonLdExportUrl}
                />
            ),
            creators: null, // Rendered inside AbstractSection
            contributors: null, // Rendered inside AbstractSection
            funders: null, // Rendered inside AbstractSection
            keywords: null, // Rendered inside AbstractSection
            metadata_download: null, // Rendered inside AbstractSection
            location: <LocationSection key="location" geoLocations={resource.geo_locations || []} isDark={isDark} />,
        };
    }, [resource, landingPage, isDark]);

    const leftSectionRegistry = useMemo((): Record<LeftColumnSection, ReactNode> => {
        return {
            files: (
                <FilesSection
                    key="files"
                    downloadUrl={landingPage?.ftp_url}
                    downloadFiles={landingPage?.files}
                    licenses={resource.licenses || []}
                    contactPersons={resource.contact_persons || []}
                    datasetTitle={mainTitle}
                    additionalLinks={landingPage?.links}
                />
            ),
            contact: <ContactSection key="contact" contactPersons={resource.contact_persons || []} datasetTitle={mainTitle} />,
            model_description: <ModelDescriptionSection key="model_description" relatedIdentifiers={resource.related_identifiers || []} />,
            related_work: <RelatedWorkSection key="related_work" relatedIdentifiers={resource.related_identifiers || []} relatedItems={resource.related_items || []} resource={resource} />,
            // IGSN-only sections — not rendered in the default resource template
            general: null,
            acquisition: null,
        };
    }, [resource, landingPage, mainTitle]);

    return (
        <>
            {schemaOrgJsonLd && (
                <Head>
                    <script type="application/ld+json">{JSON.stringify(schemaOrgJsonLd)}</script>
                </Head>
            )}
            <div data-landing-page className="min-h-screen bg-gfz-primary pt-6 dark:bg-gray-950">
                {/* Skip Navigation Link */}
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-gfz-primary focus:shadow-lg"
                >
                    Skip to main content
                </a>

                {isPreview && (
                    <div role="status" className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">
                        Preview Mode
                    </div>
                )}

                <div className="mx-auto max-w-7xl rounded-xl bg-white dark:bg-gray-900">
                    <header aria-label="GFZ Data Services" className="px-4 py-2">
                        <div className="mb-1 flex items-center justify-end gap-3">
                            <a
                                href="/legal-notice"
                                className="text-xs text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                Legal Notice
                            </a>
                            <span className="text-xs text-gray-300 dark:text-gray-600" aria-hidden="true">
                                |
                            </span>
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
                            <img src={logoSrc} alt="GFZ Data Services" className="h-24 dark:brightness-200 dark:invert" />
                        </div>
                    </header>

                    <main id="main-content" aria-label="Dataset details" tabIndex={-1}>
                        {/* Hero Section - Full Width */}
                        <ResourceHero resourceType={resourceType} status={status} mainTitle={mainTitle} subtitle={subtitle} citation={citation} />

                        {/* Two Column Layout — Abstract first on mobile for logical reading order */}
                        <div className="mx-8 mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                            {/* Right Column (Abstract, Location) — show first on mobile */}
                            <div className="order-1 space-y-6 lg:order-2 lg:col-span-2">
                                {rightOrder.map((key) => rightSectionRegistry[key]).filter(Boolean)}
                            </div>

                            {/* Left Column (Files, Contact, Related) — show second on mobile */}
                            <div className="order-2 space-y-6 lg:order-1 lg:col-span-1">
                                {leftOrder.map((key) => leftSectionRegistry[key]).filter(Boolean)}
                            </div>
                        </div>
                    </main>

                    <footer aria-label="Institutional links" className="border-t border-gray-300 px-4 py-6 dark:border-gray-700">
                        <div className="flex items-center justify-between">
                            <a href="https://www.gfz.de" target="_blank" rel="noopener noreferrer">
                                <DarkModeImage lightSrc="/images/gfz-logo-en.gif" darkSrc="/images/gfz-logo_en.svg" alt="GFZ" className="h-12" />
                            </a>
                            <a href="https://www.helmholtz.de" target="_blank" rel="noopener noreferrer">
                                <img src="/images/helmholtz-logo-blue.png" alt="Helmholtz" className="h-8 dark:brightness-200 dark:invert" />
                            </a>
                        </div>
                    </footer>
                </div>

                <BackToTopButton />
                <LandingPageToaster position="bottom-right" richColors theme={isDark ? 'dark' : 'light'} />
            </div>
        </>
    );
}
