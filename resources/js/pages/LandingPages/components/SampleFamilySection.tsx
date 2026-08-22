import type { ReactNode } from 'react';

import type { LandingPageIgsnFamilyNode, LandingPageIgsnSampleFamily } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';

interface SampleFamilySectionProps {
    family: LandingPageIgsnSampleFamily | null | undefined;
    currentResourceId: number;
}

interface FamilyNodeProps {
    node: LandingPageIgsnFamilyNode;
    currentResourceId: number;
    depth: number;
}

type SampleTypeIconKind = 'core' | 'hole' | 'sample' | 'generic';

function sampleTypeIconKind(sampleType: string | null): SampleTypeIconKind {
    const normalizedType = sampleType?.trim().toLocaleLowerCase() ?? '';

    if (normalizedType.includes('hole')) {
        return 'hole';
    }

    if (normalizedType.includes('core')) {
        return 'core';
    }

    if (['sample', 'specimen', 'section', 'fragment'].some((term) => normalizedType.includes(term))) {
        return 'sample';
    }

    return 'generic';
}

interface SampleTypeIconProps {
    sampleType: string | null;
    isCurrent: boolean;
    isPublished: boolean;
}

function SampleTypeIcon({ sampleType, isCurrent, isPublished }: SampleTypeIconProps): ReactNode {
    const typeLabel = sampleType?.trim() || 'Unknown';
    const kind = sampleTypeIconKind(sampleType);
    const color = isCurrent
        ? 'text-gfz-primary dark:text-blue-300'
        : isPublished
          ? 'text-gfz-primary dark:text-blue-400'
          : 'text-gray-400 dark:text-gray-500';

    return (
        <span
            role="img"
            aria-label={`Sample type: ${typeLabel}`}
            title={typeLabel}
            className={`mt-0.5 flex size-6 shrink-0 items-center justify-center ${color} ${
                isCurrent ? 'rounded-md bg-gfz-primary/10 ring-2 ring-gfz-primary/15 dark:bg-blue-300/10 dark:ring-blue-300/15' : ''
            }`}
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth="2">
                {kind === 'hole' ? <circle cx="12" cy="12" r="7.5" strokeWidth="3" /> : null}
                {kind === 'core' ? (
                    <>
                        <rect x="8" y="1.5" width="8" height="21" rx="3" />
                        <path d="M8 8h8M8 16h8" />
                    </>
                ) : null}
                {kind === 'sample' ? <path d="m12 3 9 9-9 9-9-9 9-9Z" fill="currentColor" fillOpacity="0.16" /> : null}
                {kind === 'generic' ? <rect x="4.5" y="4.5" width="15" height="15" rx="2" /> : null}
            </svg>
        </span>
    );
}

function FamilyNode({ node, currentResourceId, depth }: FamilyNodeProps): ReactNode {
    const isCurrent = node.resource_id === currentResourceId;
    const name = node.name?.trim();
    const igsn = node.igsn?.trim();
    const sampleType = node.sample_type?.trim();
    const primaryLabel = name || igsn || 'Unnamed sample';
    const showIgsn = Boolean(igsn && igsn !== primaryLabel);
    const showMetadata = Boolean(sampleType || showIgsn);

    const content = (
        <>
            <span className="min-w-0 flex-1">
                <span className="block font-medium break-words text-gray-900 dark:text-gray-100">{primaryLabel}</span>
                {showMetadata ? (
                    <span className="block text-xs break-all text-gray-500 dark:text-gray-400">
                        {sampleType ? <span>{sampleType}</span> : null}
                        {sampleType && showIgsn ? <span aria-hidden="true"> · </span> : null}
                        {showIgsn ? <span>IGSN {igsn}</span> : null}
                    </span>
                ) : null}
            </span>
            {isCurrent ? (
                <span className="ml-5 basis-full rounded-full bg-gfz-primary/10 px-2 py-0.5 text-center text-xs font-medium text-gfz-primary sm:ml-0 sm:shrink-0 sm:basis-auto dark:bg-blue-400/15 dark:text-blue-300">
                    Current sample
                </span>
            ) : null}
        </>
    );

    return (
        <li role="treeitem" aria-level={depth} aria-current={isCurrent ? 'page' : undefined} aria-expanded={node.children.length ? true : undefined}>
            {node.landing_page && !isCurrent ? (
                <a
                    href={node.landing_page.public_url}
                    className="flex flex-wrap items-start gap-3 rounded-md border border-transparent px-3 py-2 transition-colors hover:border-gfz-primary/30 hover:bg-gfz-primary/5 focus-visible:ring-2 focus-visible:ring-gfz-primary focus-visible:ring-offset-2 focus-visible:outline-none dark:hover:border-blue-400/40 dark:hover:bg-blue-400/10 dark:focus-visible:ring-blue-400 dark:focus-visible:ring-offset-gray-800"
                >
                    <SampleTypeIcon sampleType={node.sample_type} isCurrent={isCurrent} isPublished />
                    {content}
                </a>
            ) : (
                <div
                    className={`flex flex-wrap items-start gap-3 rounded-md border px-3 py-2 ${
                        isCurrent ? 'border-gfz-primary/40 bg-gfz-primary/5 dark:border-blue-400/50 dark:bg-blue-400/10' : 'border-transparent'
                    }`}
                >
                    <SampleTypeIcon sampleType={node.sample_type} isCurrent={isCurrent} isPublished={Boolean(node.landing_page)} />
                    {content}
                </div>
            )}

            {node.children.length ? (
                <ul role="group" className="ml-2 border-l border-gray-300 pl-2 sm:ml-4 sm:pl-3 dark:border-gray-600">
                    {node.children.map((child) => (
                        <FamilyNode key={child.resource_id} node={child} currentResourceId={currentResourceId} depth={depth + 1} />
                    ))}
                </ul>
            ) : null}
        </li>
    );
}

/** Complete locally known parent/child navigation for IGSN landing pages. */
export function SampleFamilySection({ family, currentResourceId }: SampleFamilySectionProps): ReactNode {
    if (!family || family.member_count <= 1) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-sample-family">
            <h2 id="heading-sample-family" className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Sample Family
            </h2>
            <p id="sample-family-description" className="mb-4 text-sm text-gray-600 dark:text-gray-300">
                Complete sampling hierarchy known to ERNIE ({family.member_count} samples). Select a published sample to open its landing page.
            </p>
            <div className="max-h-[32rem] overflow-auto pr-1">
                <ul role="tree" aria-describedby="sample-family-description" aria-label="Sample family hierarchy" className="min-w-64 space-y-1">
                    <FamilyNode node={family.root} currentResourceId={currentResourceId} depth={1} />
                </ul>
            </div>
        </LandingPageCard>
    );
}
