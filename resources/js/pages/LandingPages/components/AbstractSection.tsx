import { useMemo } from 'react';

import type {
    LandingPageContributor,
    LandingPageCreator,
    LandingPageDescription,
    LandingPageDisplayLimits,
    LandingPageFundingReference,
    LandingPageMetadataLink,
    LandingPageSubject,
} from '@/types/landing-page';

import { mergeLandingPageCredits } from '../lib/mergeLandingPageCredits';
import { expandMetadataOrder, filterDescriptionsBySection, isDescriptionSectionKey, type MetadataSectionKey } from '../lib/metadata-sections';
import { ContributorsSection } from './ContributorsSection';
import { CreatorsSection } from './CreatorsSection';
import { DescriptionSection } from './DescriptionSection';
import { DownloadMetadataSection } from './DownloadMetadataSection';
import { FundersSection } from './FundersSection';
import { hasVisibleKeywords, KeywordsSection } from './KeywordsSection';
import { LandingPageCard } from './LandingPageCard';

interface AbstractSectionProps {
    descriptions: LandingPageDescription[];
    creators: LandingPageCreator[];
    contributors: LandingPageContributor[];
    fundingReferences: LandingPageFundingReference[];
    subjects: LandingPageSubject[];
    resourceId: number;
    /** Public JSON-LD export URL for landing pages (avoids auth-protected routes) */
    jsonLdExportUrl?: string;
    metadataLinks?: LandingPageMetadataLink[];
    sectionOrder?: MetadataSectionKey[];
    displayLimits?: LandingPageDisplayLimits;
}

/**
 * Metadata card composition root for the landing page right column.
 *
 * Wraps description modules, Creators, Contributors, Funders, Keywords,
 * and Download Metadata inside a single shared LandingPageCard.
 */
export function AbstractSection({
    descriptions,
    creators,
    contributors,
    fundingReferences,
    subjects,
    resourceId,
    jsonLdExportUrl,
    metadataLinks,
    sectionOrder = ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
    displayLimits = { creators: 50, contributors: 50, citationAuthors: 50 },
}: AbstractSectionProps) {
    const expandedSectionOrder = expandMetadataOrder(sectionOrder);
    const displayCredits = useMemo(() => mergeLandingPageCredits(creators, contributors), [creators, contributors]);

    const renderedSections = expandedSectionOrder
        .map((sectionKey) => {
            if (isDescriptionSectionKey(sectionKey)) {
                if (filterDescriptionsBySection(descriptions, sectionKey).length === 0) {
                    return null;
                }

                return <DescriptionSection key={sectionKey} descriptions={descriptions} sectionKey={sectionKey} />;
            }

            switch (sectionKey) {
                case 'creators':
                    return displayCredits.creators.length > 0 ? (
                        <CreatorsSection key="creators" creators={displayCredits.creators} displayLimit={displayLimits.creators} />
                    ) : null;
                case 'contributors':
                    return displayCredits.contributors.length > 0 ? (
                        <ContributorsSection
                            key="contributors"
                            contributors={displayCredits.contributors}
                            displayLimit={displayLimits.contributors}
                        />
                    ) : null;
                case 'funders':
                    return fundingReferences.length > 0 ? <FundersSection key="funders" fundingReferences={fundingReferences} /> : null;
                case 'keywords':
                    return hasVisibleKeywords(subjects) ? <KeywordsSection key="keywords" subjects={subjects} /> : null;
                case 'metadata_download':
                    return (
                        <DownloadMetadataSection
                            key="metadata_download"
                            resourceId={resourceId}
                            jsonLdExportUrl={jsonLdExportUrl}
                            metadataLinks={metadataLinks}
                        />
                    );
                default:
                    return null;
            }
        })
        .filter(Boolean);

    if (renderedSections.length === 0) {
        return null;
    }

    return (
        <LandingPageCard data-testid="metadata-section">
            <div className="[&>*:first-child]:mt-0">{renderedSections}</div>
        </LandingPageCard>
    );
}
