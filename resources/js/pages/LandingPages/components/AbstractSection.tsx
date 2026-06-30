import type {
    LandingPageContributor,
    LandingPageCreator,
    LandingPageDescription,
    LandingPageDisplayLimits,
    LandingPageFundingReference,
    LandingPageSubject,
} from '@/types/landing-page';

import { expandMetadataOrder, isDescriptionSectionKey, type MetadataSectionKey } from '../lib/metadata-sections';
import { ContributorsSection } from './ContributorsSection';
import { CreatorsSection } from './CreatorsSection';
import { DescriptionSection } from './DescriptionSection';
import { DownloadMetadataSection } from './DownloadMetadataSection';
import { FundersSection } from './FundersSection';
import { KeywordsSection } from './KeywordsSection';
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
    sectionOrder = ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
    displayLimits = { creators: 50, contributors: 50, citationAuthors: 50 },
}: AbstractSectionProps) {
    const expandedSectionOrder = expandMetadataOrder(sectionOrder);

    const renderedSections = expandedSectionOrder
        .map((sectionKey) => {
            if (isDescriptionSectionKey(sectionKey)) {
                return <DescriptionSection key={sectionKey} descriptions={descriptions} sectionKey={sectionKey} />;
            }

            switch (sectionKey) {
                case 'creators':
                    return <CreatorsSection key="creators" creators={creators} displayLimit={displayLimits.creators} />;
                case 'contributors':
                    return <ContributorsSection key="contributors" contributors={contributors} displayLimit={displayLimits.contributors} />;
                case 'funders':
                    return <FundersSection key="funders" fundingReferences={fundingReferences} />;
                case 'keywords':
                    return <KeywordsSection key="keywords" subjects={subjects} />;
                case 'metadata_download':
                    return <DownloadMetadataSection key="metadata_download" resourceId={resourceId} jsonLdExportUrl={jsonLdExportUrl} />;
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
            <div className="[&>*:first-child]:mt-0">
                {renderedSections}
            </div>
        </LandingPageCard>
    );
}
