import { usePage } from '@inertiajs/react';
import { type ReactNode, useMemo } from 'react';

import type {
    LandingPageCitationStyle,
    LandingPageConfig,
    LandingPageDisplayLimits,
    LandingPageResource,
    LeftColumnSection,
    SectionOrder,
} from '@/types/landing-page';

import { AbstractSection } from './components/AbstractSection';
import { CiteThisResourceSection } from './components/CiteThisResourceSection';
import { ContactSection } from './components/ContactSection';
import { DatesSection } from './components/DatesSection';
import { FilesSection } from './components/FilesSection';
import { LandingPageShell } from './components/LandingPageShell';
import { LocationSection } from './components/LocationSection';
import { ModelDescriptionSection } from './components/ModelDescriptionSection';
import { RelatedWorkSection } from './components/RelatedWorkSection';
import { ResourceHero } from './components/ResourceHero';
import { useSystemDarkMode } from './hooks/useSystemDarkMode';
import { getLandingPageTemplateData } from './lib/landing-page-template-data';
import { type MetadataSectionKey } from './lib/metadata-sections';
import { normalizeLeftColumnOrder, RESOURCE_LEFT_COLUMN_SECTIONS, RIGHT_COLUMN_SECTIONS } from './lib/section-catalog';

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
    displayLimits?: LandingPageDisplayLimits;
    citationStyles?: LandingPageCitationStyle[];
    /** Inertia PageProps requires index signature for dynamic SSR props */
    [key: string]: unknown;
}

const DEFAULT_DISPLAY_LIMITS: LandingPageDisplayLimits = {
    creators: 50,
    contributors: 50,
    citationAuthors: 50,
};

export default function DefaultGfzTemplate() {
    const { resource, landingPage, isPreview, schemaOrgJsonLd, sectionOrder, customLogoUrl, displayLimits, citationStyles } =
        usePage<DefaultGfzTemplatePageProps>().props;
    const isDark = useSystemDarkMode();
    const peopleDisplayLimits = displayLimits ?? DEFAULT_DISPLAY_LIMITS;

    const resourceType = resource.resource_type?.name || 'Other';
    const { status, mainTitle, subtitle, citation } = getLandingPageTemplateData(
        resource,
        landingPage,
        isPreview,
        peopleDisplayLimits.citationAuthors,
    );

    const rightOrder = sectionOrder?.rightColumn ?? RIGHT_COLUMN_SECTIONS;
    const leftOrder = sectionOrder?.leftColumn ? normalizeLeftColumnOrder(sectionOrder.leftColumn, 'resource') : RESOURCE_LEFT_COLUMN_SECTIONS;
    const downloadsUnavailable = landingPage?.downloads_unavailable === true;
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
            files: downloadsUnavailable ? null : (
                <FilesSection
                    key="files"
                    downloadUrl={landingPage?.tracked_ftp_url ?? landingPage?.ftp_url}
                    downloadFiles={landingPage?.files}
                    licenses={resource.licenses || []}
                    contactPersons={resource.contact_persons || []}
                    datasetTitle={mainTitle}
                    additionalLinks={landingPage?.links}
                />
            ),
            citation: (
                <CiteThisResourceSection
                    key="citation"
                    resource={resource}
                    citationStyles={citationStyles}
                    citationAuthorLimit={peopleDisplayLimits.citationAuthors}
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
            // IGSN-only sections — not rendered in the default resource template
            general: null,
            acquisition: null,
        };
    }, [resource, landingPage, mainTitle, downloadsUnavailable, citationStyles, peopleDisplayLimits.citationAuthors]);

    return (
        <LandingPageShell
            isPreview={isPreview}
            isDark={isDark}
            mainAriaLabel="Dataset details"
            schemaOrgJsonLd={schemaOrgJsonLd}
            customLogoUrl={customLogoUrl}
            hero={<ResourceHero resourceType={resourceType} status={status} mainTitle={mainTitle} subtitle={subtitle} citation={citation} />}
            metadataSection={rightSectionRegistry.metadata}
            locationSection={rightSectionRegistry.location}
            renderLocationBeforeMetadata={renderLocationBeforeMetadata}
            leftColumnSections={leftOrder.map((key) => leftSectionRegistry[key]).filter(Boolean)}
        />
    );
}
