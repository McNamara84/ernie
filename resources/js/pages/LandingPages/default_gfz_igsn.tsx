import { usePage } from '@inertiajs/react';
import { type ReactNode, useMemo } from 'react';

import type {
    LandingPageCitationStyle,
    LandingPageConfig,
    LandingPageDisplayLimits,
    LandingPageMetadataLink,
    LandingPageResource,
    LeftColumnSection,
    SectionOrder,
} from '@/types/landing-page';

import { AbstractSection } from './components/AbstractSection';
import { AcquisitionSection } from './components/AcquisitionSection';
import { CiteThisResourceSection } from './components/CiteThisResourceSection';
import { ContactSection } from './components/ContactSection';
import { DatesSection } from './components/DatesSection';
import { GeneralSection } from './components/GeneralSection';
import { LandingPageShell } from './components/LandingPageShell';
import { LicenseAndRightsSection } from './components/LicenseAndRightsSection';
import { LocationSection } from './components/LocationSection';
import { ModelDescriptionSection } from './components/ModelDescriptionSection';
import { RelatedWorkSection } from './components/RelatedWorkSection';
import { RepositoriesSection } from './components/RepositoriesSection';
import { ResourceHero } from './components/ResourceHero';
import { SampleFamilySection } from './components/SampleFamilySection';
import { useSystemDarkMode } from './hooks/useSystemDarkMode';
import { replaceIgsnIdentifierText } from './lib/igsn-display';
import { getLandingPageTemplateData } from './lib/landing-page-template-data';
import { type MetadataSectionKey } from './lib/metadata-sections';
import { IGSN_LEFT_COLUMN_SECTIONS, normalizeLeftColumnOrder, RIGHT_COLUMN_SECTIONS } from './lib/section-catalog';

/**
 * Props passed to IGSN landing page template via Inertia
 *
 * Uses centralized types from @/types/landing-page.ts
 */
interface DefaultGfzIgsnTemplatePageProps {
    resource: LandingPageResource;
    landingPage: LandingPageConfig | null;
    isPreview: boolean;
    sectionOrder?: SectionOrder | null;
    customLogoUrl?: string | null;
    displayLimits?: LandingPageDisplayLimits;
    citationStyles?: LandingPageCitationStyle[];
    metadataLinks?: LandingPageMetadataLink[];
    /** Inertia PageProps requires index signature for dynamic SSR props */
    [key: string]: unknown;
}

const DEFAULT_DISPLAY_LIMITS: LandingPageDisplayLimits = {
    creators: 50,
    contributors: 50,
    citationAuthors: 50,
};

/**
 * Default GFZ IGSN Landing Page Template
 *
 * Two-column landing page for physical samples (IGSNs). Mirrors the layout
 * of the Default GFZ Data Services template but replaces the Files module
 * with IGSN-specific General and Acquisition modules in the left column.
 */
export default function DefaultGfzIgsnTemplate() {
    const { resource, landingPage, isPreview, metadataLinks, sectionOrder, customLogoUrl, displayLimits, citationStyles } =
        usePage<DefaultGfzIgsnTemplatePageProps>().props;
    const isDark = useSystemDarkMode();
    const peopleDisplayLimits = displayLimits ?? DEFAULT_DISPLAY_LIMITS;

    const templateData = getLandingPageTemplateData(resource, landingPage, isPreview, peopleDisplayLimits.citationAuthors);
    const localName = resource.igsn_metadata?.name?.trim();
    const mainTitle = templateData.mainTitle.trim().toLowerCase() === ':tba' && localName ? localName : templateData.mainTitle;
    const citation = replaceIgsnIdentifierText(templateData.citation, resource.doi, resource.igsn_metadata?.igsn);
    const citationPresentation = {
        ...templateData.citationPresentation,
        compact: replaceIgsnIdentifierText(templateData.citationPresentation.compact, resource.doi, resource.igsn_metadata?.igsn),
        expanded: replaceIgsnIdentifierText(templateData.citationPresentation.expanded, resource.doi, resource.igsn_metadata?.igsn),
        compactPrefix: replaceIgsnIdentifierText(templateData.citationPresentation.compactPrefix, resource.doi, resource.igsn_metadata?.igsn),
        compactSuffix: replaceIgsnIdentifierText(templateData.citationPresentation.compactSuffix, resource.doi, resource.igsn_metadata?.igsn),
    };
    const { status, subtitle } = templateData;

    const rightOrder = sectionOrder?.rightColumn ?? RIGHT_COLUMN_SECTIONS;
    const leftOrder = sectionOrder?.leftColumn ? normalizeLeftColumnOrder(sectionOrder.leftColumn, 'igsn') : IGSN_LEFT_COLUMN_SECTIONS;
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
                    metadataLinks={metadataLinks}
                    sectionOrder={metadataOrder}
                    displayLimits={peopleDisplayLimits}
                />
            ),
            location: (
                <LocationSection
                    key="location"
                    geoLocations={resource.geo_locations || []}
                    isDark={isDark}
                    samplingLocation
                    igsn={resource.igsn_metadata}
                />
            ),
        };
    }, [resource, landingPage, isDark, metadataOrder, peopleDisplayLimits, metadataLinks]);

    const leftSectionRegistry = useMemo((): Record<LeftColumnSection, ReactNode> => {
        return {
            // The IGSN template never renders the Files module — the data flow is
            // physical-sample-centric and there are no downloadable artefacts.
            files: null,
            licenses: <LicenseAndRightsSection key="licenses" licenses={resource.licenses || []} />,
            general: <GeneralSection key="general" igsn={resource.igsn_metadata} dates={resource.dates || []} />,
            sample_family: <SampleFamilySection key="sample_family" family={resource.igsn_sample_family} currentResourceId={resource.id} />,
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
            repositories: <RepositoriesSection key="repositories" igsn={resource.igsn_metadata} datasetTitle={mainTitle} />,
            citation: (
                <CiteThisResourceSection
                    key="citation"
                    resource={resource}
                    citationStyles={citationStyles}
                    citationAuthorLimit={peopleDisplayLimits.citationAuthors}
                    displayIdentifier={resource.igsn_metadata?.igsn}
                />
            ),
            dates: <DatesSection key="dates" dates={resource.dates || []} />,
            contact: <ContactSection key="contact" contactPersons={resource.contact_persons || []} datasetTitle={mainTitle} />,
            model_description: (
                <ModelDescriptionSection
                    key="model_description"
                    relatedIdentifiers={resource.related_identifiers || []}
                    resourceType={resource.resource_type?.name}
                />
            ),
            related_work: (
                <RelatedWorkSection
                    key="related_work"
                    relatedIdentifiers={resource.related_identifiers || []}
                    relatedItems={resource.related_items || []}
                    resource={resource}
                    useIgsnHandles
                />
            ),
        };
    }, [resource, mainTitle, citationStyles, peopleDisplayLimits.citationAuthors]);

    return (
        <LandingPageShell
            isPreview={isPreview}
            isDark={isDark}
            mainAriaLabel="Sample details"
            customLogoUrl={customLogoUrl}
            hero={
                <ResourceHero
                    resourceType="IGSN"
                    status={status}
                    mainTitle={mainTitle}
                    subtitle={subtitle}
                    citation={citation}
                    citationPresentation={citationPresentation}
                    useIgsnIcon={true}
                />
            }
            metadataSection={rightSectionRegistry.metadata}
            locationSection={rightSectionRegistry.location}
            renderLocationBeforeMetadata={renderLocationBeforeMetadata}
            leftColumnSections={leftOrder.map((key) => leftSectionRegistry[key]).filter(Boolean)}
        />
    );
}
