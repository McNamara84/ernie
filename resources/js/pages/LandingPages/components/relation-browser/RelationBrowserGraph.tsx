import { select } from 'd3-selection';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

import { normalizeDoiKey, resolveIdentifierUrl } from '../../lib/resolveIdentifierUrl';
import type { GraphLink, GraphNode, TooltipState } from './graph-types';
import { truncateLabel } from './graph-utils';
import { RelationBrowserTooltip } from './RelationBrowserTooltip';
import { getCitationKey, useCitationLabels } from './use-citation-labels';
import { useContributorNodes } from './use-contributor-nodes';
import { useCreatorNodes } from './use-creator-nodes';
import { useInstitutionNodes } from './use-institution-nodes';
import { useRelationGraph } from './use-relation-graph';

const GRAPH_WIDTH = 1000;
const GRAPH_HEIGHT = 700;

interface RelationBrowserGraphProps {
    resource: LandingPageResource;
    relatedIdentifiers: LandingPageRelatedIdentifier[];
    citationTexts?: Map<string, string>;
    onPersonNodesChange?: (hasCreators: boolean, hasContributors: boolean, hasInstitutions: boolean, linkRelationTypes: string[]) => void;
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

/**
 * Build graph nodes from pre-filtered related identifiers.
 * Callers must ensure identifiers have resolvable URLs before passing them here.
 */
function buildNodes(
    resource: LandingPageResource,
    relatedIdentifiers: LandingPageRelatedIdentifier[],
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
        nodeType: 'resource',
        orcid: null,
    };

    const relatedNodes: GraphNode[] = relatedIdentifiers.map((rel) => {
        const displayId = rel.identifier_type === 'DOI'
            ? normalizeDoiKey(rel.identifier)
            : rel.identifier;
        return {
            id: `related-${rel.id}`,
            label: displayId,
            fullLabel: displayId,
            identifier: displayId,
            identifierType: rel.identifier_type,
            relationType: rel.relation_type,
            url: resolveIdentifierUrl(rel.identifier, rel.identifier_type),
            isCentral: false,
            nodeType: 'resource' as const,
            orcid: null,
        };
    });

    return [centralNode, ...relatedNodes];
}

/**
 * Build graph links from pre-filtered related identifiers.
 * Callers must ensure identifiers have resolvable URLs before passing them here.
 */
function buildLinks(relatedIdentifiers: LandingPageRelatedIdentifier[]): GraphLink[] {
    return relatedIdentifiers.map((rel) => ({
        source: 'central',
        target: `related-${rel.id}`,
        relationType: rel.relation_type,
        relationLabel: rel.relation_type.replace(/([A-Z])/g, ' $1').trim(),
    }));
}

export function RelationBrowserGraph({ resource, relatedIdentifiers, citationTexts, onPersonNodesChange }: RelationBrowserGraphProps) {
    const svgRef = useRef<SVGSVGElement | null>(null);
    const containerRef = useRef<HTMLDivElement | null>(null);
    const [tooltip, setTooltip] = useState<TooltipState>({
        visible: false,
        x: 0,
        y: 0,
        content: { label: '', identifier: '', identifierType: '' },
        type: 'node',
    });

    const citationLabels = useCitationLabels(relatedIdentifiers, citationTexts);
    const { creatorNodes, creatorLinks, creatorNodeIdMap, apiAuthorsWithAffiliations } = useCreatorNodes(resource, relatedIdentifiers);
    const { contributorNodes, contributorLinks, contributorNodeIdMap } = useContributorNodes(resource);
    const { institutionNodes, institutionLinks } = useInstitutionNodes(
        resource,
        creatorNodeIdMap,
        contributorNodeIdMap,
        apiAuthorsWithAffiliations,
    );

    // Collect actual relation types from person/institution links for legend
    const personLinkRelationTypes = useMemo(
        () => [...new Set([...creatorLinks, ...contributorLinks, ...institutionLinks].map((l) => l.relationType))],
        [creatorLinks, contributorLinks, institutionLinks],
    );

    // Report person/institution node presence and link relation types to parent (for legend)
    useEffect(() => {
        onPersonNodesChange?.(creatorNodes.length > 0, contributorNodes.length > 0, institutionNodes.length > 0, personLinkRelationTypes);
    }, [creatorNodes.length, contributorNodes.length, institutionNodes.length, personLinkRelationTypes, onPersonNodesChange]);

    // Stable node/link references: only rebuild when identifiers change, not on every citation update.
    // Citation labels are patched into existing nodes separately to avoid restarting the simulation.
    const resourceNodes = useMemo(
        () => buildNodes(resource, relatedIdentifiers),
        [resource, relatedIdentifiers],
    );

    // Merge resource nodes with creator, contributor, and institution nodes
    const nodes = useMemo(
        () => [...resourceNodes, ...creatorNodes, ...contributorNodes, ...institutionNodes],
        [resourceNodes, creatorNodes, contributorNodes, institutionNodes],
    );

    // Patch citation labels into existing node objects without creating new array
    useEffect(() => {
        if (citationLabels.size === 0) return;
        for (const node of resourceNodes) {
            if (node.isCentral) continue;
            const key = getCitationKey(node.identifierType, node.identifier);
            const citation = citationLabels.get(key);
            if (citation) {
                node.label = citation.shortLabel;
                node.fullLabel = citation.fullCitation;
            }
        }
        // Update text labels in the SVG without restarting simulation
        if (svgRef.current) {
            select(svgRef.current)
                .selectAll<SVGTextElement, GraphNode>('[data-testid="graph-nodes"] g text')
                .text((d) => truncateLabel(d.label));
        }
    }, [citationLabels, resourceNodes, svgRef]);

    const resourceLinks = useMemo(
        () => buildLinks(relatedIdentifiers),
        [relatedIdentifiers],
    );

    // Merge resource links with creator, contributor, and institution links
    const links = useMemo(
        () => [...resourceLinks, ...creatorLinks, ...contributorLinks, ...institutionLinks],
        [resourceLinks, creatorLinks, contributorLinks, institutionLinks],
    );

    const handleNodeClick = useCallback((node: GraphNode) => {
        if (node.url) {
            window.open(node.url, '_blank', 'noopener,noreferrer');
        }
    }, []);

    const graphOptions = useMemo(
        () => ({
            width: GRAPH_WIDTH,
            height: GRAPH_HEIGHT,
            onTooltipChange: setTooltip,
            onNodeClick: handleNodeClick,
        }),
        [handleNodeClick],
    );

    useRelationGraph(svgRef, nodes, links, graphOptions);

    const containerRect = containerRef.current?.getBoundingClientRect() ?? null;

    return (
        <div
            ref={containerRef}
            className="absolute inset-0 overflow-hidden"
            data-testid="relation-browser-graph"
        >
            <svg
                ref={svgRef}
                viewBox={`0 0 ${GRAPH_WIDTH} ${GRAPH_HEIGHT}`}
                preserveAspectRatio="xMidYMid meet"
                className="h-full w-full"
                style={{ display: 'block' }}
                role="img"
                aria-label="Relation Browser Graph"
            />
            <RelationBrowserTooltip tooltip={tooltip} containerRect={containerRect} />
        </div>
    );
}
