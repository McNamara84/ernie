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
}

type SampleTypeIconKind =
    | 'individual-sample'
    | 'core-whole-round'
    | 'core-section'
    | 'specimen'
    | 'core'
    | 'site'
    | 'core-sample'
    | 'cuttings'
    | 'ctd'
    | 'terrestrial-section'
    | 'core-half-round'
    | 'grab'
    | 'hole'
    | 'dredge'
    | 'other'
    | 'unknown';

/** All 15 sample types in the 35,429-record legacy IGSN Solr index. */
const LEGACY_SAMPLE_TYPE_ICONS: Readonly<Record<string, SampleTypeIconKind>> = {
    'individual sample': 'individual-sample',
    'core whole round': 'core-whole-round',
    'core section': 'core-section',
    specimen: 'specimen',
    core: 'core',
    site: 'site',
    'core sample': 'core-sample',
    cuttings: 'cuttings',
    ctd: 'ctd',
    'terrestrial section': 'terrestrial-section',
    'core half round': 'core-half-round',
    grab: 'grab',
    hole: 'hole',
    dredge: 'dredge',
    other: 'other',
};

function sampleTypeIconKind(sampleType: string | null): SampleTypeIconKind {
    const normalizedType = sampleType?.trim().toLowerCase() ?? '';
    const legacyKind = LEGACY_SAMPLE_TYPE_ICONS[normalizedType];

    if (legacyKind) {
        return legacyKind;
    }

    if (normalizedType.includes('hole')) {
        return 'hole';
    }

    if (normalizedType.includes('core')) {
        return 'core';
    }

    if (normalizedType.includes('site') || normalizedType.includes('station')) {
        return 'site';
    }

    if (normalizedType.includes('specimen')) {
        return 'specimen';
    }

    if (['sample', 'section', 'fragment'].some((term) => normalizedType.includes(term))) {
        return 'individual-sample';
    }

    return 'unknown';
}

function SampleTypeGlyph({ kind }: { kind: SampleTypeIconKind }): ReactNode {
    switch (kind) {
        case 'individual-sample':
            return <path d="m12 3 9 9-9 9-9-9 9-9Z" fill="currentColor" fillOpacity="0.16" />;
        case 'core-whole-round':
            return (
                <>
                    <ellipse cx="12" cy="4" rx="5" ry="2.5" />
                    <path d="M7 4v16c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5V4M7 20c0-1.4 2.2-2.5 5-2.5s5 1.1 5 2.5" />
                </>
            );
        case 'core-section':
            return (
                <>
                    <rect x="8" y="1.5" width="8" height="21" rx="3" />
                    <path d="M8 8h8M8 16h8" />
                </>
            );
        case 'specimen':
            return <path d="m12 2 8 5v10l-8 5-8-5V7l8-5Z" fill="currentColor" fillOpacity="0.12" />;
        case 'core':
            return <rect x="9" y="1.5" width="6" height="21" rx="3" fill="currentColor" fillOpacity="0.16" />;
        case 'site':
            return (
                <>
                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
                    <circle cx="12" cy="10" r="2.5" />
                </>
            );
        case 'core-sample':
            return (
                <>
                    <rect x="2" y="8" width="20" height="8" rx="4" />
                    <path d="M9 8v8M15 8v8" />
                </>
            );
        case 'cuttings':
            return (
                <>
                    <path d="m5 3 5 2-1 6-6-1 2-7ZM15 4l6 3-3 5-5-3 2-5ZM8 14l6-1 3 6-7 2-2-7Z" fill="currentColor" fillOpacity="0.14" />
                </>
            );
        case 'ctd':
            return (
                <>
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 3v18M7 8l5 4 5-4M8 17h8" />
                </>
            );
        case 'terrestrial-section':
            return (
                <>
                    <rect x="2.5" y="4" width="19" height="16" rx="1" />
                    <path d="M3 10c4-2 7 2 11 0s5-1 7 0M3 15c4-2 7 2 11 0s5-1 7 0" />
                </>
            );
        case 'core-half-round':
            return (
                <>
                    <path d="M8 2h2c4.4 0 7 3.6 7 8v4c0 4.4-2.6 8-7 8H8V2Z" />
                    <path d="M8 2v20" />
                </>
            );
        case 'grab':
            return <path d="M5 3v7a7 7 0 0 0 14 0V3M5 9l7 5 7-5M12 14v7M9 21h6" />;
        case 'hole':
            return <circle cx="12" cy="12" r="7.5" strokeWidth="3" />;
        case 'dredge':
            return (
                <>
                    <path d="M7 3h10M12 3v5M5 8h14l-2 13H7L5 8Z" />
                    <path d="M7 13h10M8 17h8" />
                </>
            );
        case 'other':
            return (
                <>
                    <circle cx="5" cy="12" r="1.5" fill="currentColor" stroke="none" />
                    <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                    <circle cx="19" cy="12" r="1.5" fill="currentColor" stroke="none" />
                </>
            );
        case 'unknown':
            return <rect x="4.5" y="4.5" width="15" height="15" rx="2" strokeDasharray="3 2" />;
    }
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
            data-sample-type-icon={kind}
            className={`mt-0.5 flex size-6 shrink-0 items-center justify-center ${color} ${
                isCurrent ? 'rounded-md bg-gfz-primary/10 ring-2 ring-gfz-primary/15 dark:bg-blue-300/10 dark:ring-blue-300/15' : ''
            }`}
        >
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                className="size-5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <SampleTypeGlyph kind={kind} />
            </svg>
        </span>
    );
}

function FamilyNode({ node, currentResourceId }: FamilyNodeProps): ReactNode {
    const isCurrent = node.resource_id === currentResourceId;
    const name = node.name?.trim();
    const igsn = node.igsn?.trim();
    const primaryLabel = name || igsn || 'Unnamed sample';
    const showIgsn = Boolean(igsn && igsn !== primaryLabel);

    const content = (
        <>
            <span className="min-w-0 flex-1">
                <span className="block font-medium break-words text-gray-900 dark:text-gray-100">{primaryLabel}</span>
                {showIgsn ? <span className="block text-xs break-all text-gray-500 dark:text-gray-400">IGSN {igsn}</span> : null}
            </span>
            {isCurrent ? (
                <span className="ml-5 basis-full rounded-full bg-gfz-primary/10 px-2 py-0.5 text-center text-xs font-medium text-gfz-primary sm:ml-0 sm:shrink-0 sm:basis-auto dark:bg-blue-400/15 dark:text-blue-300">
                    Current sample
                </span>
            ) : null}
        </>
    );

    return (
        <li aria-current={isCurrent ? 'page' : undefined}>
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
                <ul className="ml-2 border-l border-gray-300 pl-2 sm:ml-4 sm:pl-3 dark:border-gray-600">
                    {node.children.map((child) => (
                        <FamilyNode key={child.resource_id} node={child} currentResourceId={currentResourceId} />
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
            <nav aria-labelledby="heading-sample-family" className="max-h-[32rem] overflow-auto pr-1">
                <ul className="min-w-64 space-y-1">
                    <FamilyNode node={family.root} currentResourceId={currentResourceId} />
                </ul>
            </nav>
        </LandingPageCard>
    );
}
