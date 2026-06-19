import { usePage } from '@inertiajs/react';
import { type ReactNode, useMemo } from 'react';

import type { LandingPageConfig, LandingPageDisplayLimits, LandingPageResource, LeftColumnSection, SectionOrder } from '@/types/landing-page';

import { AbstractSection } from './components/AbstractSection';
import { AcquisitionSection } from './components/AcquisitionSection';
import { ContactSection } from './components/ContactSection';
import { DatesSection } from './components/DatesSection';
import { GeneralSection } from './components/GeneralSection';
import { LandingPageShell } from './components/LandingPageShell';
import { LocationSection } from './components/LocationSection';
import { ModelDescriptionSection } from './components/ModelDescriptionSection';
import { RelatedWorkSection } from './components/RelatedWorkSection';
import { ResourceHero } from './components/ResourceHero';
import { useSystemDarkMode } from './hooks/useSystemDarkMode';
import { getLandingPageTemplateData } from './lib/landing-page-template-data';
import { type MetadataSectionKey } from './lib/metadata-sections';
import { IGSN_LEFT_COLUMN_SECTIONS, RIGHT_COLUMN_SECTIONS } from './lib/section-catalog';

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
    sectionOrder?: SectionOrder | null;
    customLogoUrl?: string | null;
    displayLimits?: LandingPageDisplayLimits;
    /** Inertia PageProps requires index signature for dynamic SSR props */
    [key: string]: unknown;
}

const DEFAULT_DISPLAY_LIMITS: LandingPageDisplayLimits = {
    creators: 50,
    contributors: 50,
};

/**
 * Default GFZ IGSN Landing Page Template
 *
 * Two-column landing page for physical samples (IGSNs). Mirrors the layout
 * of the Default GFZ Data Services template but replaces the Files module
 * with IGSN-specific General and Acquisition modules in the left column.
 */
export default function DefaultGfzIgsnTemplate() {
    const { resource, landingPage, isPreview, schemaOrgJsonLd, sectionOrder, customLogoUrl, displayLimits } =
        usePage<DefaultGfzIgsnTemplatePageProps>().props;
    const isDark = useSystemDarkMode();
    const peopleDisplayLimits = displayLimits ?? DEFAULT_DISPLAY_LIMITS;

    const { status, mainTitle, subtitle, citation } = getLandingPageTemplateData(resource, landingPage, isPreview);

    const rightOrder = sectionOrder?.rightColumn ?? RIGHT_COLUMN_SECTIONS;
    const leftOrder = sectionOrder?.leftColumn ?? IGSN_LEFT_COLUMN_SECTIONS;
    const metadataOrder = rightOrder.filter((key): key is MetadataSectionKey => key !== 'location');
    const firstMetadataIndex = rightOrder.findIndex((key) => key !== 'location');
    const locationIndex = rightOrder.indexOf('location');
    const renderLocationBeforeMetadata = locationIndex !== -1 && (firstMetadataIndex === -1 || locationIndex < firstMetadataIndex);

    const rightSectionRegistry = useMemo((): { metadata: ReactNode; location: ReactNode } => {
        const jsonLdExportUrl = landingPage?.public_url ? `${landingPage.public_url}/jsonld` : undefined;
        return {
            metadata: (
                <AbstractSection
                    key="metadata"
                    descriptions={resource.descriptions || []}
                    creators={resource.creators || []}
                    contributors={resource.contributors || []}
                    fundingReferences={resource.funding_references || []}
                    subjects={resource.subjects || []}
                    resourceId={resource.id}
                    jsonLdExportUrl={jsonLdExportUrl}
                    sectionOrder={metadataOrder}
                    displayLimits={peopleDisplayLimits}
                />
            ),
            location: <LocationSection key="location" geoLocations={resource.geo_locations || []} isDark={isDark} />,
        };
    }, [resource, landingPage, isDark, metadataOrder, peopleDisplayLimits]);

    const leftSectionRegistry = useMemo((): Record<LeftColumnSection, ReactNode> => {
        return {
            // The IGSN template never renders the Files module — the data flow is
            // physical-sample-centric and there are no downloadable artefacts.
            files: null,
            general: (
                <GeneralSection
                    key="general"
                    igsn={resource.igsn_metadata}
                    doi={resource.doi}
                    fundingReferences={resource.funding_references || []}
                    dates={resource.dates || []}
                />
            ),
            acquisition: (
                <AcquisitionSection
                    key="acquisition"
                    igsn={resource.igsn_metadata}
                    classifications={resource.igsn_classifications || []}
                    descriptions={resource.descriptions || []}
                    contributors={resource.contributors || []}
                    fundingReferences={resource.funding_references || []}
                    dates={resource.dates || []}
                />
            ),
            dates: <DatesSection key="dates" dates={resource.dates || []} />,
            contact: <ContactSection key="contact" contactPersons={resource.contact_persons || []} datasetTitle={mainTitle} />,
            model_description: <ModelDescriptionSection key="model_description" relatedIdentifiers={resource.related_identifiers || []} />,
            related_work: (
                <RelatedWorkSection
                    key="related_work"
                    relatedIdentifiers={resource.related_identifiers || []}
                    relatedItems={resource.related_items || []}
                    resource={resource}
                />
            ),
        };
    }, [resource, mainTitle]);

    return (
        <LandingPageShell
            isPreview={isPreview}
            isDark={isDark}
            mainAriaLabel="Sample details"
            schemaOrgJsonLd={schemaOrgJsonLd}
            customLogoUrl={customLogoUrl}
            hero={
                <ResourceHero resourceType="IGSN" status={status} mainTitle={mainTitle} subtitle={subtitle} citation={citation} useIgsnIcon={true} />
            }
            metadataSection={rightSectionRegistry.metadata}
            locationSection={rightSectionRegistry.location}
            renderLocationBeforeMetadata={renderLocationBeforeMetadata}
            leftColumnSections={leftOrder.map((key) => leftSectionRegistry[key]).filter(Boolean)}
        />
    );
}
