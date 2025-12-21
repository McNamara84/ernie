import { Head } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

interface LandingPageLayoutProps extends PropsWithChildren {
    isPreview?: boolean;
    title?: string;
    description?: string;
    keywords?: string[];
    canonicalUrl?: string;
    ogImage?: string;
    jsonLd?: Record<string, unknown>;
}

/**
 * Dedicated layout for public-facing landing pages with SEO optimization
 *
 * Features:
 * - Schema.org JSON-LD structured data for datasets
 * - Open Graph meta tags for social media sharing
 * - Twitter Card meta tags
 * - Canonical URLs
 * - Page title and meta description
 * - Preview mode banner for draft pages
 * - Minimal footer with GFZ branding
 * - Dark mode support
 * - Responsive design
 */
export default function LandingPageLayout({
    children,
    isPreview = false,
    title,
    description,
    keywords = [],
    canonicalUrl,
    ogImage,
    jsonLd,
}: LandingPageLayoutProps) {
    return (
        <>
            <Head>
                {/* Primary Meta Tags */}
                {title && <title>{title} - GFZ Data Services</title>}
                {description && <meta name="description" content={description} />}
                {keywords.length > 0 && <meta name="keywords" content={keywords.join(', ')} />}

                {/* Canonical URL */}
                {canonicalUrl && <link rel="canonical" href={canonicalUrl} />}

                {/* Open Graph / Facebook */}
                <meta property="og:type" content="dataset" />
                {title && <meta property="og:title" content={title} />}
                {description && <meta property="og:description" content={description} />}
                {canonicalUrl && <meta property="og:url" content={canonicalUrl} />}
                <meta property="og:site_name" content="GFZ Data Services" />
                {ogImage && <meta property="og:image" content={ogImage} />}

                {/* Twitter Card */}
                <meta name="twitter:card" content="summary_large_image" />
                {title && <meta name="twitter:title" content={title} />}
                {description && <meta name="twitter:description" content={description} />}
                {ogImage && <meta name="twitter:image" content={ogImage} />}

                {/* Robots */}
                {isPreview ? <meta name="robots" content="noindex, nofollow" /> : <meta name="robots" content="index, follow" />}

                {/* Schema.org JSON-LD */}
                {jsonLd && <script type="application/ld+json">{JSON.stringify(jsonLd, null, 2)}</script>}
            </Head>

            <div className="flex min-h-screen flex-col bg-white dark:bg-gray-900">
                {/* Preview Mode Banner */}
                {isPreview && (
                    <div className="border-b border-yellow-200 bg-yellow-100 dark:border-yellow-800 dark:bg-yellow-900">
                        <div className="container mx-auto px-4 py-3">
                            <p className="text-center text-sm font-medium text-yellow-900 dark:text-yellow-100">
                                🔍 Preview Mode - This landing page is not publicly visible yet
                            </p>
                        </div>
                    </div>
                )}

                {/* Main Content */}
                <main className="container mx-auto max-w-7xl flex-1 px-4 py-8">{children}</main>

                {/* Footer */}
                <footer className="mt-12 border-t border-gray-200 bg-gray-50 py-8 dark:border-gray-700 dark:bg-gray-800">
                    <div className="container mx-auto max-w-7xl px-4">
                        <div className="flex flex-col items-center justify-between gap-4 text-sm text-gray-600 md:flex-row dark:text-gray-400">
                            {/* Left: Branding */}
                            <div className="text-center md:text-left">
                                <p className="font-semibold text-gray-900 dark:text-white">GFZ Data Services</p>
                                <p className="mt-1">GFZ German Research Centre for Geosciences</p>
                            </div>

                            {/* Right: Links */}
                            <div className="flex flex-wrap items-center justify-center gap-4">
                                <a
                                    href="https://www.gfz-potsdam.de"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="transition-colors hover:text-gray-900 hover:underline dark:hover:text-white"
                                >
                                    www.gfz-potsdam.de
                                </a>
                                <span className="text-gray-300 dark:text-gray-600">•</span>
                                <a
                                    href="https://dataservices.gfz-potsdam.de"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="transition-colors hover:text-gray-900 hover:underline dark:hover:text-white"
                                >
                                    Data Services
                                </a>
                                <span className="text-gray-300 dark:text-gray-600">•</span>
                                <a href="/imprint" className="transition-colors hover:text-gray-900 hover:underline dark:hover:text-white">
                                    Impressum
                                </a>
                                <span className="text-gray-300 dark:text-gray-600">•</span>
                                <a href="/privacy" className="transition-colors hover:text-gray-900 hover:underline dark:hover:text-white">
                                    Privacy Policy
                                </a>
                            </div>
                        </div>

                        {/* Copyright */}
                        <div className="mt-6 text-center text-xs text-gray-500 dark:text-gray-500">
                            <p>© {new Date().getFullYear()} GFZ German Research Centre for Geosciences. All rights reserved.</p>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
