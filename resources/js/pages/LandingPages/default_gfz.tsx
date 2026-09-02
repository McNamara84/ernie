import { Head, usePage } from '@inertiajs/react';
import { type ReactNode, useMemo } from 'react';

import type {
    LandingPageCitationStyle,
    LandingPageConfig,
    LandingPageDisplayLimits,
    LandingPageMetadataLink,
    LandingPageResource,
    LandingPageTypeVisibility,
    ResourceSection,
    SectionOrder,
} from '@/types/landing-page';

import { AbstractSection } from './components/AbstractSection';
import { CiteThisResourceSection } from './components/CiteThisResourceSection';
import { ContactSection } from './components/ContactSection';
import { DatesSection } from './components/DatesSection';
import { FilesSection } from './components/FilesSection';
import { LandingPageShell } from './components/LandingPageShell';
import { LicenseAndRightsSection } from './components/LicenseAndRightsSection';
import { LocationSection } from './components/LocationSection';
import { ModelDescriptionSection } from './components/ModelDescriptionSection';
import { RelatedWorkSection } from './components/RelatedWorkSection';
import { ResourceHero } from './components/ResourceHero';
import { useSystemDarkMode } from './hooks/useSystemDarkMode';
import { getLandingPageTemplateData } from './lib/landing-page-template-data';
import { type MetadataSectionKey } from './lib/metadata-sections';
import {
    normalizeResourceColumnOrders,
    RESOURCE_LEFT_COLUMN_SECTIONS,
    RESOURCE_METADATA_SECTIONS,
    RIGHT_COLUMN_SECTIONS,
} from './lib/section-catalog';

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
    documentTitle: string;
    landingPage: LandingPageConfig | null;
    isPreview: boolean;
    sectionOrder?: SectionOrder | null;
    customLogoUrl?: string | null;
    displayLimits?: LandingPageDisplayLimits;
    typeVisibility?: LandingPageTypeVisibility;
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

const RESOURCE_METADATA_SECTION_SET = new Set<ResourceSection>(RESOURCE_METADATA_SECTIONS);
type ResourceMetadataSectionKey = Exclude<MetadataSectionKey, 'descriptions'>;

function metadataOrderForColumn(order: readonly ResourceSection[]): ResourceMetadataSectionKey[] {
    return order.filter((key): key is ResourceMetadataSectionKey => RESOURCE_METADATA_SECTION_SET.has(key));
}

function composeResourceColumn(
    order: readonly ResourceSection[],
    standaloneSections: Partial<Record<ResourceSection, ReactNode>>,
    metadataSection: ReactNode,
): ReactNode[] {
    let metadataAdded = false;

    return order
        .map((key) => {
            if (RESOURCE_METADATA_SECTION_SET.has(key)) {
                if (metadataAdded) return null;
                metadataAdded = true;

                return metadataSection;
            }

            return standaloneSections[key] ?? null;
        })
        .filter((section): section is ReactNode => section !== null && section !== undefined && section !== false);
}

export default function DefaultGfzTemplate() {
    const {
        resource,
        documentTitle,
        landingPage,
        isPreview,
        metadataLinks,
        sectionOrder,
        customLogoUrl,
        displayLimits,
        citationStyles,
        typeVisibility,
    } = usePage<DefaultGfzTemplatePageProps>().props;
    const isDark = useSystemDarkMode();
    const peopleDisplayLimits = displayLimits ?? DEFAULT_DISPLAY_LIMITS;

    const resourceType = resource.resource_type?.name || 'Other';
    const { status, mainTitle, subtitle, citation, citationPresentation } = getLandingPageTemplateData(
        resource,
        landingPage,
        isPreview,
        peopleDisplayLimits.citationAuthors,
    );

    const orders = sectionOrder
        ? normalizeResourceColumnOrders(sectionOrder.leftColumn, sectionOrder.rightColumn)
        : {
              left: RESOURCE_LEFT_COLUMN_SECTIONS as ResourceSection[],
              right: RIGHT_COLUMN_SECTIONS as ResourceSection[],
          };
    const downloadsUnavailable = landingPage?.downloads_unavailable === true;
    const leftMetadataOrder = metadataOrderForColumn(orders.left);
    const rightMetadataOrder = metadataOrderForColumn(orders.right);

    const metadataSections = useMemo((): { left: ReactNode; right: ReactNode } => {
        const jsonLdExportUrl = landingPage?.public_url ? `${landingPage.public_url}/jsonld` : undefined;
        const renderMetadataSection = (key: string, metadataOrder: MetadataSectionKey[]): ReactNode =>
            metadataOrder.length === 0 ? null : (
                <AbstractSection
                    key={key}
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
            );

        return {
            left: renderMetadataSection('left-metadata', leftMetadataOrder),
            right: renderMetadataSection('right-metadata', rightMetadataOrder),
        };
    }, [resource, landingPage, leftMetadataOrder, rightMetadataOrder, peopleDisplayLimits, metadataLinks]);

    const standaloneSectionRegistry = useMemo((): Partial<Record<ResourceSection, ReactNode>> => {
        return {
            files: downloadsUnavailable ? null : (
                <FilesSection
                    key="files"
                    downloadUrl={landingPage?.ftp_url}
                    trackedDownloadUrl={landingPage?.tracked_ftp_url}
                    downloadLabel={landingPage?.primary_download_label}
                    downloadFiles={landingPage?.files}
                    contactPersons={resource.contact_persons || []}
                    datasetTitle={mainTitle}
                    additionalLinks={landingPage?.links}
                />
            ),
            licenses: <LicenseAndRightsSection key="licenses" licenses={resource.licenses || []} />,
            citation: (
                <CiteThisResourceSection
                    key="citation"
                    resource={resource}
                    citationStyles={citationStyles}
                    citationAuthorLimit={peopleDisplayLimits.citationAuthors}
                />
            ),
            dates: <DatesSection key="dates" dates={resource.dates || []} excludedDateTypes={typeVisibility?.excludedDateTypes} />,
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
                    excludedRelationTypes={typeVisibility?.excludedRelationTypes}
                />
            ),
            location: <LocationSection key="location" geoLocations={resource.geo_locations || []} isDark={isDark} />,
        };
    }, [resource, landingPage, mainTitle, downloadsUnavailable, citationStyles, peopleDisplayLimits.citationAuthors, isDark, typeVisibility]);

    const leftColumnSections = composeResourceColumn(orders.left, standaloneSectionRegistry, metadataSections.left);
    const rightColumnSections = composeResourceColumn(orders.right, standaloneSectionRegistry, metadataSections.right);

    return (
        <>
            <Head title={documentTitle}>{isPreview && <meta head-key="landing-page-robots" name="robots" content="noindex, nofollow" />}</Head>
            <LandingPageShell
                isPreview={isPreview}
                isDark={isDark}
                mainAriaLabel="Dataset details"
                customLogoUrl={customLogoUrl}
                hero={
                    <ResourceHero
                        resourceType={resourceType}
                        status={status}
                        mainTitle={mainTitle}
                        subtitle={subtitle}
                        citation={citation}
                        citationPresentation={citationPresentation}
                    />
                }
                rightColumnSections={rightColumnSections}
                leftColumnSections={leftColumnSections}
            />
        </>
    );
}
