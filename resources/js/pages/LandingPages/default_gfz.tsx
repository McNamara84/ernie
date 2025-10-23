 
 
 
 
 
/* eslint-disable @typescript-eslint/no-explicit-any */
import { usePage } from '@inertiajs/react';

import AuthorsList from '@/components/landing-pages/shared/AuthorsList';
import CitationBox from '@/components/landing-pages/shared/CitationBox';
import ContactSection from '@/components/landing-pages/shared/ContactSection';
import DatasetDescription from '@/components/landing-pages/shared/DatasetDescription';
import FilesDownload from '@/components/landing-pages/shared/FilesDownload';
import FundersSection from '@/components/landing-pages/shared/FundersSection';
import KeywordsSection from '@/components/landing-pages/shared/KeywordsSection';
import LocationMap from '@/components/landing-pages/shared/LocationMap';
import MetadataLinks from '@/components/landing-pages/shared/MetadataLinks';
import QRCodeGenerator from '@/components/landing-pages/shared/QRCodeGenerator';
import RelatedWork from '@/components/landing-pages/shared/RelatedWork';
import ViewStatistics from '@/components/landing-pages/shared/ViewStatistics';
import { withBasePath } from '@/lib/base-path';

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Get main title from resource titles
 */
function getMainTitle(titles: any[]): string {
    const mainTitle = titles.find((t: any) => !t.title_type || t.title_type === 'MainTitle');
    return mainTitle?.title || titles[0]?.title || 'Untitled Resource';
}

/**
 * Get abstract description from resource descriptions
 */
function getAbstract(descriptions: any[]): string | null {
    const abstract = descriptions.find(
        (d: any) => !d.description_type || d.description_type === 'Abstract',
    );
    return abstract?.description || descriptions[0]?.description || null;
}

/**
 * Get contact authors (authors with Contact Person role)
 */
function getContactAuthors(authors: any[]): any[] {
    return authors.filter((author: any) =>
        author.roles.some((role: any) => role.name === 'ContactPerson'),
    );
}

/**
 * Build landing page URL for QR code
 */
function buildLandingPageUrl(
    resourceId: number,
    isPreview: boolean,
    previewToken?: string | null,
): string {
    const baseUrl = window.location.origin;
    const path = withBasePath(`/datasets/${resourceId}`);
    const url = `${baseUrl}${path}`;

    if (isPreview && previewToken) {
        return `${url}?preview=${previewToken}`;
    }

    return url;
}

// ============================================================================
// Component
// ============================================================================

/**
 * Default GFZ Landing Page Template
 *
 * Full-featured landing page template for GFZ Data Services.
 * Combines all components from Sprint 4 (shared) and Sprint 5 (advanced).
 *
 * Layout:
 * - Full-width header with title and DOI
 * - 2-column layout (main content 2/3, sidebar 1/3)
 * - Main: Citation, Authors, Description, Related Work, Keywords, Location Map
 * - Sidebar: Statistics, Files, Metadata, QR Code, Contact, Funders
 *
 * Features:
 * - Responsive design (stacks on mobile)
 * - Dark mode support
 * - Preview banner for draft pages
 * - Conditional rendering based on data availability
 * - Proper semantic HTML structure
 */
export default function DefaultGfzTemplate() {
    const { resource, landingPage, isPreview } = usePage().props as any;

    const mainTitle = getMainTitle(resource.titles);
    const abstract = getAbstract(resource.descriptions);
    const contactAuthors = getContactAuthors(resource.authors);
    const landingPageUrl = buildLandingPageUrl(
        resource.id,
        isPreview,
        landingPage.preview_token,
    );

    return (
        <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
            {/* Preview Banner */}
            {isPreview && (
                <div className="bg-yellow-500 px-4 py-2 text-center text-sm font-medium text-yellow-900">
                    ⚠️ Preview Mode - This landing page is not yet published
                </div>
            )}

            {/* Header */}
            <header className="border-b border-gray-200 bg-white px-4 py-8 dark:border-gray-700 dark:bg-gray-800 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-7xl">
                    <h1 className="mb-2 text-3xl font-bold text-gray-900 dark:text-gray-100 sm:text-4xl">
                        {mainTitle}
                    </h1>
                    {resource.doi && (
                        <p className="text-lg text-gray-600 dark:text-gray-400">
                            <span className="font-medium">DOI:</span>{' '}
                            <a
                                href={`https://doi.org/${resource.doi as string}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                {resource.doi as string}
                            </a>
                        </p>
                    )}
                    {resource.resource_type && (
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {resource.resource_type.name as string}
                        </p>
                    )}
                </div>
            </header>

            {/* Main Content */}
            <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="grid gap-8 lg:grid-cols-3">
                    {/* Main Column (2/3) */}
                    <div className="space-y-8 lg:col-span-2">
                        {/* Citation Box */}
                        <section>
                            <CitationBox resource={resource} />
                        </section>

                        {/* Abstract */}
                        {abstract && (
                            <section>
                                <h2 className="mb-4 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                    Abstract
                                </h2>
                                <p className="text-gray-700 dark:text-gray-300">{abstract}</p>
                            </section>
                        )}

                        {/* Authors */}
                        {resource.authors?.length > 0 && (
                            <section>
                                <AuthorsList
                                    resource={resource}
                                    heading="Authors & Contributors"
                                />
                            </section>
                        )}

                        {/* Location Map */}
                        {resource.coverages && resource.coverages.length > 0 && (
                            <section>
                                <LocationMap
                                    resource={resource}
                                    heading="Geographic Coverage"
                                    height="400px"
                                />
                            </section>
                        )}

                        {/* Dataset Description */}
                        <section>
                            <DatasetDescription resource={resource} />
                        </section>

                        {/* Related Work */}
                        {resource.related_identifiers && resource.related_identifiers.length > 0 && (
                            <section>
                                <RelatedWork
                                    resource={resource}
                                    heading="Related Resources"
                                />
                            </section>
                        )}

                        {/* Keywords */}
                        {resource.keywords && resource.keywords.length > 0 && (
                            <section>
                                <KeywordsSection resource={resource} heading="Keywords" />
                            </section>
                        )}
                    </div>

                    {/* Sidebar (1/3) */}
                    <aside className="space-y-8">
                        {/* View Statistics (only for published pages) */}
                        {!isPreview && (
                            <section>
                                <ViewStatistics
                                    viewCount={landingPage.view_count as number}
                                    lastViewedAt={(landingPage.last_viewed_at as string) ?? null}
                                    heading="Page Views"
                                />
                            </section>
                        )}

                        {/* Files Download */}
                        {(landingPage.ftp_url || resource.doi) && (
                            <section>
                                <FilesDownload
                                    resource={resource}
                                    config={landingPage}
                                    heading="Download Files"
                                    showLicenseDetails={true}
                                />
                            </section>
                        )}

                        {/* Metadata Export */}
                        <section>
                            <MetadataLinks
                                resource={resource}
                                heading="Export Metadata"
                                showDescriptions={true}
                            />
                        </section>

                        {/* QR Code */}
                        <section>
                            <QRCodeGenerator
                                url={landingPageUrl}
                                heading="Share this Page"
                                size={200}
                                level="M"
                                showUrl={true}
                            />
                        </section>

                        {/* Contact */}
                        {contactAuthors.length > 0 && (
                            <section>
                                <ContactSection
                                    resource={{ ...resource, authors: contactAuthors }}
                                    heading="Contact Information"
                                />
                            </section>
                        )}

                        {/* Funders */}
                        {resource.funding_references && resource.funding_references.length > 0 && (
                            <section>
                                <FundersSection
                                    resource={resource}
                                    heading="Funding"
                                />
                            </section>
                        )}
                    </aside>
                </div>
            </main>

            {/* Footer */}
            <footer className="border-t border-gray-200 bg-white px-4 py-6 dark:border-gray-700 dark:bg-gray-800 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-7xl text-center text-sm text-gray-600 dark:text-gray-400">
                    <p>
                        Published by {(resource.publisher as string) || 'GFZ Data Services'}
                        {resource.year && ` · ${resource.year as number}`}
                    </p>
                    {landingPage.published_at && (
                        <p className="mt-1">
                            Landing page published:{' '}
                            {new Date(landingPage.published_at as string).toLocaleDateString(
                                'en-US',
                                {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                },
                            )}
                        </p>
                    )}
                </div>
            </footer>
        </div>
    );
}
