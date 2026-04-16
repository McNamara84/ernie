import { Head, usePage } from '@inertiajs/react';

import type { LandingPageConfig, LandingPageResource } from '@/types/landing-page';

import { LandingPageToaster } from './components/LandingPageToaster';
import { ResourceHero } from './components/ResourceHero';
import { useSystemDarkMode } from './hooks/useSystemDarkMode';
import { buildCitation } from './lib/buildCitation';

/**
 * Props passed to IGSN landing page template via Inertia
 *
 * Uses centralized types from @/types/landing-page.ts
 */
interface DefaultGfzIgsnTemplatePageProps {
    resource: LandingPageResource;
    landingPage: LandingPageConfig | null;
    isPreview: boolean;
    schemaOrgJsonLd?: Record<string, unknown>;
    /** Inertia PageProps requires index signature for dynamic SSR props */
    [key: string]: unknown;
}

/**
 * Default GFZ IGSN Landing Page Template
 *
 * A simplified landing page template for physical samples (IGSNs).
 * Shows only the essential metadata: header, ResourceHero (with IGSN label), and footer.
 *
 * Additional IGSN-specific sections (sample type, material, collection date, etc.)
 * will be added in future iterations.
 */
export default function DefaultGfzIgsnTemplate() {
    const { resource, landingPage, isPreview, schemaOrgJsonLd } = usePage<DefaultGfzIgsnTemplatePageProps>().props;
    const isDark = useSystemDarkMode();

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
            <div data-landing-page className="min-h-screen bg-gfz-primary pt-6 dark:bg-gray-950">
                {/* Skip Navigation Link */}
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-gfz-primary focus:shadow-lg"
                >
                    Skip to main content
                </a>

                {isPreview && <div role="status" className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">Preview Mode</div>}

                <div className="mx-auto max-w-7xl rounded bg-white dark:bg-gray-900">
                    {/* Header */}
                    <header className="px-4 py-2">
                        {/* Legal Notice & Data Protection - top right */}
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
                        {/* Logo - centered */}
                        <div className="flex justify-center">
                            <img src="/images/gfz-ds-logo.png" alt="GFZ Data Services" className="h-24 dark:brightness-200 dark:invert" />
                        </div>
                    </header>

                    {/* Content - Only ResourceHero for IGSN */}
                    <main id="main-content" tabIndex={-1} className="pb-6">
                        <ResourceHero
                            resourceType="IGSN"
                            status={status}
                            mainTitle={mainTitle}
                            subtitle={subtitle}
                            citation={citation}
                            useIgsnIcon={true}
                        />
                    </main>

                    {/* Footer */}
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

                <LandingPageToaster position="bottom-right" richColors theme={isDark ? 'dark' : 'light'} />
            </div>
        </>
    );
}
