import { GripHorizontal } from 'lucide-react';
import { type PointerEvent as ReactPointerEvent, type ReactNode, useCallback, useEffect, useId, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageIgsnFamilyNode, LandingPageIgsnSampleFamily } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';

interface SampleFamilySectionProps {
    family: LandingPageIgsnSampleFamily | null | undefined;
    currentResourceId: number;
}

interface FamilyNodeProps {
    node: LandingPageIgsnFamilyNode;
    currentResourceId: number;
    expandedIds: ReadonlySet<number>;
    onToggle: (resourceId: number) => void;
    idPrefix: string;
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

const INITIAL_MAX_HEIGHT = 512;

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

function FamilyNode({ node, currentResourceId, expandedIds, onToggle, idPrefix }: FamilyNodeProps): ReactNode {
    const isCurrent = node.resource_id === currentResourceId;
    const hasChildren = node.children.length > 0;
    const isExpanded = hasChildren && expandedIds.has(node.resource_id);
    const childrenId = `${idPrefix}-children-${node.resource_id}`;
    const name = node.name?.trim();
    const igsn = node.igsn?.trim();
    const primaryLabel = name || igsn || 'Unnamed sample';
    const showIgsn = Boolean(igsn && igsn !== primaryLabel);

    const content = (
        <>
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium break-words text-gray-900 dark:text-gray-100">{primaryLabel}</span>
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
            <div className="flex min-w-0 items-start" data-family-node-row>
                {hasChildren ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11 shrink-0 text-gray-600 hover:bg-gray-100 focus-visible:ring-gfz-primary dark:text-gray-300 dark:hover:bg-gray-700 dark:focus-visible:ring-blue-400"
                        aria-label={`${isExpanded ? 'Collapse' : 'Expand'} ${primaryLabel}`}
                        aria-expanded={isExpanded}
                        aria-controls={childrenId}
                        onClick={() => onToggle(node.resource_id)}
                    >
                        <svg
                            className={`size-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                            viewBox="0 0 20 20"
                            fill="none"
                            aria-hidden="true"
                        >
                            <path d="m7 4 6 6-6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                    </Button>
                ) : (
                    <span className="size-11 shrink-0" aria-hidden="true" />
                )}
                {node.landing_page && !isCurrent ? (
                    <a
                        href={node.landing_page.public_url}
                        className="flex min-w-0 flex-1 flex-wrap items-start gap-3 rounded-md border border-transparent px-3 py-2 transition-colors hover:border-gfz-primary/30 hover:bg-gfz-primary/5 focus-visible:ring-2 focus-visible:ring-gfz-primary focus-visible:ring-offset-2 focus-visible:outline-none dark:hover:border-blue-400/40 dark:hover:bg-blue-400/10 dark:focus-visible:ring-blue-400 dark:focus-visible:ring-offset-gray-800"
                    >
                        <SampleTypeIcon sampleType={node.sample_type} isCurrent={isCurrent} isPublished />
                        {content}
                    </a>
                ) : (
                    <div
                        className={`flex min-w-0 flex-1 flex-wrap items-start gap-3 rounded-md border px-3 py-2 ${
                            isCurrent ? 'border-gfz-primary/40 bg-gfz-primary/5 dark:border-blue-400/50 dark:bg-blue-400/10' : 'border-transparent'
                        }`}
                    >
                        <SampleTypeIcon sampleType={node.sample_type} isCurrent={isCurrent} isPublished={Boolean(node.landing_page)} />
                        {content}
                    </div>
                )}
            </div>

            {hasChildren && isExpanded ? (
                <ul id={childrenId} className="ml-5 border-l border-gray-300 pl-1 sm:ml-7 sm:pl-2 dark:border-gray-600">
                    {node.children.map((child) => (
                        <FamilyNode
                            key={child.resource_id}
                            node={child}
                            currentResourceId={currentResourceId}
                            expandedIds={expandedIds}
                            onToggle={onToggle}
                            idPrefix={idPrefix}
                        />
                    ))}
                </ul>
            ) : null}
        </li>
    );
}

/** Return exactly the ancestors that must be open to reveal the current sample. */
export function findExpandedAncestorIds(root: LandingPageIgsnFamilyNode, currentResourceId: number): Set<number> {
    const visit = (node: LandingPageIgsnFamilyNode, ancestors: number[]): number[] | null => {
        if (node.resource_id === currentResourceId) return ancestors;
        for (const child of node.children) {
            const path = visit(child, [...ancestors, node.resource_id]);
            if (path) return path;
        }
        return null;
    };

    return new Set(visit(root, []) ?? []);
}

/** Complete locally known parent/child navigation for IGSN landing pages. */
export function SampleFamilySection({ family, currentResourceId }: SampleFamilySectionProps): ReactNode {
    const idPrefix = useId().replace(/:/g, '');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(() => (family ? findExpandedAncestorIds(family.root, currentResourceId) : new Set()));
    const [height, setHeight] = useState<number | null>(null);
    const [minHeight, setMinHeight] = useState(44);
    const [maxHeight, setMaxHeight] = useState(INITIAL_MAX_HEIGHT);
    const navigationRef = useRef<HTMLElement>(null);
    const treeRef = useRef<HTMLUListElement>(null);
    const resizeStart = useRef<{ pointerY: number; height: number } | null>(null);

    useEffect(() => {
        setExpandedIds(family ? findExpandedAncestorIds(family.root, currentResourceId) : new Set());
        setHeight(null);
    }, [family, currentResourceId]);

    const measure = useCallback(() => {
        const tree = treeRef.current;
        if (!tree) return;
        const firstRow = tree.querySelector<HTMLElement>('[data-family-node-row]');
        const nextMin = Math.max(44, Math.ceil(firstRow?.getBoundingClientRect().height ?? firstRow?.offsetHeight ?? 44));
        const nextMax = Math.max(nextMin, Math.ceil(tree.scrollHeight));
        setMinHeight(nextMin);
        setMaxHeight(nextMax);
        setHeight((current) => (current === null ? Math.min(nextMax, INITIAL_MAX_HEIGHT) : Math.max(nextMin, Math.min(current, nextMax))));
    }, []);

    useEffect(() => {
        measure();
        const tree = treeRef.current;
        if (!tree || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(measure);
        observer.observe(tree);
        return () => observer.disconnect();
    }, [expandedIds, measure]);

    const clampHeight = (value: number) => Math.max(minHeight, Math.min(value, maxHeight));
    const toggle = (resourceId: number) => {
        setExpandedIds((current) => {
            const next = new Set(current);
            if (next.has(resourceId)) next.delete(resourceId);
            else next.add(resourceId);
            return next;
        });
    };
    const handleResizeStart = (event: ReactPointerEvent<HTMLDivElement>) => {
        const currentHeight = navigationRef.current?.getBoundingClientRect().height ?? height ?? minHeight;
        resizeStart.current = { pointerY: event.clientY, height: currentHeight };
        event.currentTarget.setPointerCapture(event.pointerId);
        event.preventDefault();
    };
    const handleResizeMove = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!resizeStart.current) return;
        setHeight(clampHeight(resizeStart.current.height + event.clientY - resizeStart.current.pointerY));
    };
    const handleResizeEnd = (event: ReactPointerEvent<HTMLDivElement>) => {
        resizeStart.current = null;
        if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    };

    if (!family || family.member_count <= 1) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-sample-family">
            <h2 id="heading-sample-family" className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Sample Family
            </h2>
            <nav
                ref={navigationRef}
                aria-labelledby="heading-sample-family"
                className="min-w-0 overflow-auto pr-1"
                style={height === null ? { maxHeight: INITIAL_MAX_HEIGHT } : { height }}
            >
                <ul ref={treeRef} className="min-w-0 space-y-1">
                    <FamilyNode
                        node={family.root}
                        currentResourceId={currentResourceId}
                        expandedIds={expandedIds}
                        onToggle={toggle}
                        idPrefix={idPrefix}
                    />
                </ul>
            </nav>
            <div
                role="separator"
                aria-label="Resize Sample Family"
                aria-orientation="horizontal"
                aria-valuemin={Math.round(minHeight)}
                aria-valuemax={Math.round(maxHeight)}
                aria-valuenow={height === null ? undefined : Math.round(height)}
                tabIndex={height === null ? undefined : 0}
                className="mt-2 flex h-6 cursor-ns-resize touch-none items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-gfz-primary focus-visible:outline-none dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200 dark:focus-visible:ring-blue-400"
                onPointerDown={handleResizeStart}
                onPointerMove={handleResizeMove}
                onPointerUp={handleResizeEnd}
                onPointerCancel={handleResizeEnd}
                onKeyDown={(event) => {
                    const current = height ?? navigationRef.current?.getBoundingClientRect().height ?? Math.min(maxHeight, INITIAL_MAX_HEIGHT);
                    if (event.key === 'ArrowUp') setHeight(clampHeight(current - 24));
                    else if (event.key === 'ArrowDown') setHeight(clampHeight(current + 24));
                    else if (event.key === 'Home') setHeight(minHeight);
                    else if (event.key === 'End') setHeight(maxHeight);
                    else return;
                    event.preventDefault();
                }}
            >
                <GripHorizontal className="size-5" aria-hidden="true" />
            </div>
        </LandingPageCard>
    );
}
