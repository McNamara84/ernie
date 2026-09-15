import { Fragment, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import type { LandingPageRelatedIdentifier } from '@/types/landing-page';

import { resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';
import type { ResourceRelatedWorkGroup } from '../lib/resource-related-work';
import { LandingPageCard } from './LandingPageCard';
import { getCopyableCitation, hasDisplayableIdentifier, RelatedIdentifierRow, RelatedItemRow } from './RelatedWorkEntries';

interface ResourceRelationHighlightSectionsProps {
    keyPublications: ResourceRelatedWorkGroup;
    datasetDescriptions: ResourceRelatedWorkGroup;
}

interface HighlightCardProps {
    heading: string;
    headingId: string;
    testId: string;
    group: ResourceRelatedWorkGroup;
    copiedRelatedIdentifierId: number | null;
    onCopy: (relatedIdentifier: LandingPageRelatedIdentifier) => void;
}

function hasRenderableContent(group: ResourceRelatedWorkGroup): boolean {
    return group.relatedIdentifiers.some(hasDisplayableIdentifier) || group.relatedItems.length > 0;
}

function HighlightCard({ heading, headingId, testId, group, copiedRelatedIdentifierId, onCopy }: HighlightCardProps) {
    const relatedIdentifiers = group.relatedIdentifiers.filter(hasDisplayableIdentifier);
    const relatedItems = [...group.relatedItems].sort((left, right) => left.position - right.position);

    if (relatedIdentifiers.length === 0 && relatedItems.length === 0) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby={headingId} data-testid={testId}>
            <h2 id={headingId} className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h2>

            <ul className="space-y-3">
                {relatedIdentifiers.map((relatedIdentifier) => (
                    <li key={`identifier-${relatedIdentifier.id}`}>
                        <RelatedIdentifierRow
                            relatedIdentifier={relatedIdentifier}
                            url={resolveIdentifierUrl(relatedIdentifier.identifier, relatedIdentifier.identifier_type)}
                            useIgsnHandles={false}
                            copied={copiedRelatedIdentifierId === relatedIdentifier.id}
                            onCopy={onCopy}
                        />
                    </li>
                ))}
                {relatedItems.map((relatedItem) => (
                    <li key={`item-${relatedItem.id}`} data-testid={`related-item-${relatedItem.id}`}>
                        <RelatedItemRow item={relatedItem} showInlineMetadataBadge />
                    </li>
                ))}
            </ul>
        </LandingPageCard>
    );
}

/**
 * Resource-only highlighted relation cards. The containing Resource layout
 * anchors this pair immediately after License & Rights.
 */
export function ResourceRelationHighlightSections({ keyPublications, datasetDescriptions }: ResourceRelationHighlightSectionsProps) {
    const [copiedRelatedIdentifierId, setCopiedRelatedIdentifierId] = useState<number | null>(null);
    const [copyAnnouncementOperationId, setCopyAnnouncementOperationId] = useState<number | null>(null);
    const copyTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const copyOperationRef = useRef(0);

    useEffect(() => {
        return () => {
            copyOperationRef.current += 1;

            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
        };
    }, []);

    if (!hasRenderableContent(keyPublications) && !hasRenderableContent(datasetDescriptions)) {
        return null;
    }

    const clearCopiedState = () => {
        if (copyTimeoutRef.current) {
            clearTimeout(copyTimeoutRef.current);
            copyTimeoutRef.current = null;
        }

        setCopiedRelatedIdentifierId(null);
        setCopyAnnouncementOperationId(null);
    };

    const handleCopyCitation = async (relatedIdentifier: LandingPageRelatedIdentifier) => {
        const citation = getCopyableCitation(relatedIdentifier);

        if (!citation) {
            return;
        }

        const operationId = ++copyOperationRef.current;

        try {
            if (!navigator.clipboard?.writeText) {
                throw new Error('Clipboard API unavailable');
            }

            await navigator.clipboard.writeText(citation);

            if (operationId !== copyOperationRef.current) {
                return;
            }

            setCopiedRelatedIdentifierId(relatedIdentifier.id);
            setCopyAnnouncementOperationId(operationId);
            toast.success('Citation copied to clipboard');

            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }

            copyTimeoutRef.current = setTimeout(() => {
                setCopiedRelatedIdentifierId(null);
                setCopyAnnouncementOperationId(null);
                copyTimeoutRef.current = null;
            }, 2000);
        } catch {
            if (operationId !== copyOperationRef.current) {
                return;
            }

            clearCopiedState();
            toast.error('Failed to copy citation');
        }
    };

    return (
        <Fragment>
            <HighlightCard
                heading="Key Publication"
                headingId="heading-key-publication"
                testId="key-publication-section"
                group={keyPublications}
                copiedRelatedIdentifierId={copiedRelatedIdentifierId}
                onCopy={handleCopyCitation}
            />
            <HighlightCard
                heading="Dataset Description"
                headingId="heading-dataset-description"
                testId="dataset-description-section"
                group={datasetDescriptions}
                copiedRelatedIdentifierId={copiedRelatedIdentifierId}
                onCopy={handleCopyCitation}
            />
            <span className="sr-only" aria-live="polite" role="status">
                {copyAnnouncementOperationId !== null ? <span key={copyAnnouncementOperationId}>Citation copied to clipboard</span> : null}
            </span>
        </Fragment>
    );
}
