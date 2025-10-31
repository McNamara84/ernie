/* eslint-disable @typescript-eslint/no-explicit-any */
import { usePage } from '@inertiajs/react';

import { withBasePath } from '@/lib/base-path';

import { AbstractSection } from './components/AbstractSection';
import { FilesSection } from './components/FilesSection';
import { ModelDescriptionSection } from './components/ModelDescriptionSection';
import { RelatedWorkSection } from './components/RelatedWorkSection';
import { ResourceHero } from './components/ResourceHero';
import { buildCitation } from './lib/buildCitation';

export default function DefaultGfzTemplate() {
    const { resource, landingPage, isPreview } = usePage().props as any;

    // Extract data for ResourceHero
    const resourceType = resource.resource_type?.name || 'Other';
    const status = isPreview ? 'preview' : landingPage?.status || 'published';
    const mainTitle =
        resource.titles?.find(
            (t: any) => !t.title_type || t.title_type === 'MainTitle',
        )?.title || 'Untitled';
    const subtitle = resource.titles?.find((t: any) => t.title_type === 'Subtitle')
        ?.title;
    const citation = buildCitation(resource);

    return (
        <div className="min-h-screen pt-6" style={{ backgroundColor: '#0C2A63' }}>
            {isPreview && (
                <div className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">
                     Preview Mode
                </div>
            )}
            
            {/* Zentrierter Container für Header, Content und Footer */}
            <div className="mx-auto max-w-7xl rounded bg-white">
                {/* Header */}
                <header className="px-4 py-4">
                    {/* Legal Notice - ganz oben rechts */}
                    <div className="mb-2 flex justify-end">
                        <a
                            href={withBasePath('/legal-notice')}
                            className="text-xs text-gray-600 hover:text-gray-900 hover:underline"
                        >
                            Legal Notice
                        </a>
                    </div>
                    {/* Logo - zentriert */}
                    <div className="flex justify-center">
                        <img
                            src={withBasePath('/images/gfz-ds-logo.png')}
                            alt="GFZ Data Services"
                            className="h-12"
                        />
                    </div>
                </header>
                
                {/* Content */}
                <div>
                    {/* Hero Section - Full Width */}
                    <ResourceHero
                        resourceType={resourceType}
                        status={status}
                        mainTitle={mainTitle}
                        subtitle={subtitle}
                        citation={citation}
                    />

                    {/* Two Column Layout */}
                    <div className="mx-8 mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                        {/* Left Column - 1/3 width */}
                        <div className="space-y-6 lg:col-span-1">
                            <FilesSection
                                downloadUrl={landingPage?.ftp_url || '#'}
                                licenses={resource.licenses || []}
                            />
                            <ModelDescriptionSection
                                relatedIdentifiers={resource.related_identifiers || []}
                            />
                            <RelatedWorkSection
                                relatedIdentifiers={resource.related_identifiers || []}
                            />
                        </div>

                        {/* Right Column - 2/3 width */}
                        <div className="lg:col-span-2">
                            <AbstractSection 
                                descriptions={resource.descriptions || []}
                                authors={resource.authors || []}
                            />
                        </div>
                    </div>
                </div>
                
                {/* Footer */}
                <footer className="border-t border-gray-300 px-4 py-6">
                    <div className="flex items-center justify-between">
                        <a href="https://www.gfz.de" target="_blank" rel="noopener noreferrer">
                            <img src={withBasePath('/images/gfz-logo-en.gif')} alt="GFZ" className="h-12" />
                        </a>
                        <a href="https://www.helmholtz.de" target="_blank" rel="noopener noreferrer">
                            <img src={withBasePath('/images/helmholtz-logo-blue.png')} alt="Helmholtz" className="h-12" />
                        </a>
                    </div>
                </footer>
            </div>
        </div>
    );
}
