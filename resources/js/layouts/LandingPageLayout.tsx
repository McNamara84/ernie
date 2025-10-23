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
                {isPreview ? (
                    <meta name="robots" content="noindex, nofollow" />
                ) : (
                    <meta name="robots" content="index, follow" />
                )}
                
                {/* Schema.org JSON-LD */}
                {jsonLd && (
                    <script type="application/ld+json">
                        {JSON.stringify(jsonLd, null, 2)}
                    </script>
                )}
            </Head>

            <div className="min-h-screen bg-white dark:bg-gray-900 flex flex-col">
                {/* Preview Mode Banner */}
                {isPreview && (
                    <div className="bg-yellow-100 dark:bg-yellow-900 border-b border-yellow-200 dark:border-yellow-800">
                        <div className="container mx-auto px-4 py-3">
                            <p className="text-sm font-medium text-yellow-900 dark:text-yellow-100 text-center">
                                🔍 Preview Mode - This landing page is not publicly visible yet
                            </p>
                        </div>
                    </div>
                )}

                {/* Main Content */}
                <main className="flex-1 container mx-auto px-4 py-8 max-w-7xl">
                    {children}
                </main>

                {/* Footer */}
                <footer className="border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 py-8 mt-12">
                    <div className="container mx-auto px-4 max-w-7xl">
                        <div className="flex flex-col md:flex-row items-center justify-between gap-4 text-sm text-gray-600 dark:text-gray-400">
                            {/* Left: Branding */}
                            <div className="text-center md:text-left">
                                <p className="font-semibold text-gray-900 dark:text-white">
                                    GFZ Data Services
                                </p>
                                <p className="mt-1">
                                    GFZ German Research Centre for Geosciences
                                </p>
                            </div>

                            {/* Right: Links */}
                            <div className="flex flex-wrap items-center justify-center gap-4">
                                <a
                                    href="https://www.gfz-potsdam.de"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="hover:text-gray-900 dark:hover:text-white hover:underline transition-colors"
                                >
                                    www.gfz-potsdam.de
                                </a>
                                <span className="text-gray-300 dark:text-gray-600">•</span>
                                <a
                                    href="https://dataservices.gfz-potsdam.de"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="hover:text-gray-900 dark:hover:text-white hover:underline transition-colors"
                                >
                                    Data Services
                                </a>
                                <span className="text-gray-300 dark:text-gray-600">•</span>
                                <a
                                    href="/imprint"
                                    className="hover:text-gray-900 dark:hover:text-white hover:underline transition-colors"
                                >
                                    Impressum
                                </a>
                                <span className="text-gray-300 dark:text-gray-600">•</span>
                                <a
                                    href="/privacy"
                                    className="hover:text-gray-900 dark:hover:text-white hover:underline transition-colors"
                                >
                                    Privacy Policy
                                </a>
                            </div>
                        </div>

                        {/* Copyright */}
                        <div className="mt-6 text-center text-xs text-gray-500 dark:text-gray-500">
                            <p>
                                © {new Date().getFullYear()} GFZ German Research Centre for Geosciences.
                                All rights reserved.
                            </p>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
