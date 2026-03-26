import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

import { resolveIdentifierUrl } from '../../lib/resolveIdentifierUrl';

import type { CitationLabel, GraphLink, GraphNode, TooltipState } from './graph-types';
import { RelationBrowserTooltip } from './RelationBrowserTooltip';
import { getCitationKey, useCitationLabels } from './use-citation-labels';
import { useRelationGraph } from './use-relation-graph';

interface RelationBrowserGraphProps {
    resource: LandingPageResource;
    relatedIdentifiers: LandingPageRelatedIdentifier[];
}

function buildCentralLabel(resource: LandingPageResource): { shortLabel: string; fullLabel: string } {
    const creators = resource.creators ?? [];
    const firstCreator = creators[0];
    const year = resource.publication_year;

    let authorName = '';
    if (firstCreator?.creatorable) {
        authorName = firstCreator.creatorable.family_name
            ?? firstCreator.creatorable.name
            ?? '';
    }

    if (authorName && year) {
        return {
            shortLabel: `${authorName}, ${year}`,
            fullLabel: `${authorName}${creators.length > 1 ? ' et al.' : ''}, ${year}`,
        };
    }
    if (authorName) {
        return { shortLabel: authorName, fullLabel: authorName };
    }

    const mainTitle = resource.titles?.find((t) => !t.title_type || t.title_type === 'MainTitle')?.title;
    if (mainTitle) {
        return {
            shortLabel: mainTitle.length > 20 ? mainTitle.slice(0, 17) + '…' : mainTitle,
            fullLabel: mainTitle,
        };
    }

    return { shortLabel: 'This Resource', fullLabel: 'This Resource' };
}

function buildNodes(
    resource: LandingPageResource,
    relatedIdentifiers: LandingPageRelatedIdentifier[],
    citationLabels: Map<string, CitationLabel>,
): GraphNode[] {
    const centralLabel = buildCentralLabel(resource);

    const centralNode: GraphNode = {
        id: 'central',
        label: centralLabel.shortLabel,
        fullLabel: centralLabel.fullLabel,
        identifier: resource.identifier ?? '',
        identifierType: resource.identifier ? 'DOI' : 'Resource',
        relationType: '',
        url: null,
        isCentral: true,
    };

    const relatedNodes: GraphNode[] = relatedIdentifiers
        .filter((rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null)
        .map((rel) => {
            const key = getCitationKey(rel.identifier_type, rel.identifier);
            const citation = citationLabels.get(key);
            const url = resolveIdentifierUrl(rel.identifier, rel.identifier_type);

            return {
                id: `related-${rel.id}`,
                label: citation?.shortLabel ?? rel.identifier,
                fullLabel: citation?.fullCitation ?? rel.identifier,
                identifier: rel.identifier,
                identifierType: rel.identifier_type,
                relationType: rel.relation_type,
                url,
                isCentral: false,
            };
        });

    return [centralNode, ...relatedNodes];
}

function buildLinks(relatedIdentifiers: LandingPageRelatedIdentifier[]): GraphLink[] {
    return relatedIdentifiers
        .filter((rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null)
        .map((rel) => ({
            source: 'central',
            target: `related-${rel.id}`,
            relationType: rel.relation_type,
            relationLabel: rel.relation_type.replace(/([A-Z])/g, ' $1').trim(),
        }));
}

export function RelationBrowserGraph({ resource, relatedIdentifiers }: RelationBrowserGraphProps) {
    const svgRef = useRef<SVGSVGElement | null>(null);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const [dimensions, setDimensions] = useState({ width: 800, height: 500 });
    const [tooltip, setTooltip] = useState<TooltipState>({
        visible: false,
        x: 0,
        y: 0,
        content: { label: '', identifier: '', identifierType: '' },
        type: 'node',
    });

    const citationLabels = useCitationLabels(relatedIdentifiers);

    const nodes = useMemo(
        () => buildNodes(resource, relatedIdentifiers, citationLabels),
        [resource, relatedIdentifiers, citationLabels],
    );

    const links = useMemo(
        () => buildLinks(relatedIdentifiers),
        [relatedIdentifiers],
    );

    const handleNodeClick = useCallback((node: GraphNode) => {
        if (node.url) {
            window.open(node.url, '_blank', 'noopener,noreferrer');
        }
    }, []);

    const graphOptions = useMemo(
        () => ({
            width: dimensions.width,
            height: dimensions.height,
            onTooltipChange: setTooltip,
            onNodeClick: handleNodeClick,
        }),
        [dimensions.width, dimensions.height, handleNodeClick],
    );

    useRelationGraph(svgRef, nodes, links, graphOptions);

    // ResizeObserver for responsive sizing
    useEffect(() => {
        const container = containerRef.current;
        if (!container) return;

        const observer = new ResizeObserver((entries) => {
            const entry = entries[0];
            if (entry) {
                setDimensions({
                    width: entry.contentRect.width,
                    height: entry.contentRect.height,
                });
            }
        });

        observer.observe(container);
        return () => observer.disconnect();
    }, []);

    const containerRect = containerRef.current?.getBoundingClientRect() ?? null;

    return (
        <div ref={containerRef} className="relative h-full w-full" data-testid="relation-browser-graph">
            <svg ref={svgRef} className="h-full w-full" role="img" aria-label="Relation Browser Graph" />
            <RelationBrowserTooltip tooltip={tooltip} containerRect={containerRect} />
        </div>
    );
}
