import { usePage } from '@inertiajs/react';

import type { LandingPageConfig, LandingPageResource } from '@/types/landing-page';

import { ResourceHero } from './components/ResourceHero';
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
    const { resource, landingPage, isPreview } = usePage<DefaultGfzIgsnTemplatePageProps>().props;

    const status = isPreview ? 'preview' : landingPage?.status || 'published';
    const mainTitle = resource.titles?.find((t) => !t.title_type || t.title_type === 'MainTitle')?.title || 'Untitled';
    const subtitle = resource.titles?.find((t) => t.title_type === 'Subtitle')?.title;
    const citation = buildCitation(resource);

    return (
        <div className="min-h-screen bg-gfz-primary pt-6">
            {isPreview && <div className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">Preview Mode</div>}

            <div className="mx-auto max-w-7xl rounded bg-white">
                {/* Header */}
                <header className="px-4 py-2">
                    {/* Legal Notice - top right */}
                    <div className="mb-1 flex justify-end">
                        <a href="/legal-notice" className="text-xs text-gray-600 hover:text-gray-900 hover:underline">
                            Legal Notice
                        </a>
                    </div>
                    {/* Logo - centered */}
                    <div className="flex justify-center">
                        <img src="/images/gfz-ds-logo.png" alt="GFZ Data Services" className="h-16" />
                    </div>
                </header>

                {/* Content - Only ResourceHero for IGSN */}
                <div className="pb-6">
                    <ResourceHero resourceType="IGSN" status={status} mainTitle={mainTitle} subtitle={subtitle} citation={citation} useIgsnIcon={true} />
                </div>

                {/* Footer */}
                <footer className="border-t border-gray-300 px-4 py-6">
                    <div className="flex items-center justify-between">
                        <a href="https://www.gfz.de" target="_blank" rel="noopener noreferrer">
                            <img src="/images/gfz-logo-en.gif" alt="GFZ" className="h-12" />
                        </a>
                        <a href="https://www.helmholtz.de" target="_blank" rel="noopener noreferrer">
                            <img src="/images/helmholtz-logo-blue.png" alt="Helmholtz" className="h-8" />
                        </a>
                    </div>
                </footer>
            </div>
        </div>
    );
}
