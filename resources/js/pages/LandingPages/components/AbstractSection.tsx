import type {
    LandingPageContributor,
    LandingPageCreator,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageSubject,
} from '@/types/landing-page';

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
}

/**
 * Abstract Section — composition root.
 *
 * Wraps Description, Creators, Contributors, Funders, Keywords, and
 * Download Metadata sub-components inside a single LandingPageCard.
 */
export function AbstractSection({
    descriptions,
    creators,
    contributors,
    fundingReferences,
    subjects,
    resourceId,
    jsonLdExportUrl,
}: AbstractSectionProps) {
    const hasAbstract = descriptions.some((desc) => desc.description_type?.toLowerCase() === 'abstract');

    if (!hasAbstract) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-abstract" data-testid="abstract-section">
            <DescriptionSection descriptions={descriptions} />
            <CreatorsSection creators={creators} />
            <ContributorsSection contributors={contributors} />
            <FundersSection fundingReferences={fundingReferences} />
            <KeywordsSection subjects={subjects} />
            <DownloadMetadataSection resourceId={resourceId} jsonLdExportUrl={jsonLdExportUrl} />
        </LandingPageCard>
    );
}
