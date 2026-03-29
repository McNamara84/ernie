import { drag } from 'd3-drag';
import { forceCenter, forceCollide, forceLink, forceManyBody, forceSimulation, type Simulation } from 'd3-force';
import { select } from 'd3-selection';
import { zoom } from 'd3-zoom';
import { type RefObject, useCallback, useEffect, useRef } from 'react';

import { CENTRAL_RADIUS, CREATOR_RADIUS, getEdgeCategory, getEdgeColor, getNodeColor, getNodeRadius, NODE_RADIUS } from './graph-colors';
import type { GraphLink, GraphNode, TooltipState } from './graph-types';
import { truncateLabel } from './graph-utils';

interface UseRelationGraphOptions {
    width: number;
    height: number;
    onTooltipChange: (tooltip: TooltipState) => void;
    onNodeClick: (node: GraphNode) => void;
}

export function useRelationGraph(
    svgRef: RefObject<SVGSVGElement | null>,
    nodes: GraphNode[],
    links: GraphLink[],
    options: UseRelationGraphOptions,
) {
    const simulationRef = useRef<Simulation<GraphNode, GraphLink> | null>(null);
    const rafRef = useRef<number | null>(null);

    const { width, height, onTooltipChange, onNodeClick } = options;

    const hideTooltip = useCallback(() => {
        onTooltipChange({
            visible: false,
            x: 0,
            y: 0,
            content: { label: '', identifier: '', identifierType: '' },
            type: 'node',
        });
    }, [onTooltipChange]);

    useEffect(() => {
        const svg = svgRef.current;
        if (!svg || nodes.length === 0) return;

        // Clear previous
        select(svg).selectAll('*').remove();
        simulationRef.current?.stop();

        const svgSelection = select(svg);

        // Container group for zoom/pan
        const container = svgSelection.append('g').attr('data-testid', 'graph-container');

        // Arrow marker definition
        const defs = svgSelection.append('defs');
        const markerTypes = [...new Set(links.map((l) => l.relationType))];
        for (const rt of markerTypes) {
            // Created edges point at resource nodes (radius 22-30)
            // Contributor edges point at central node (radius 30)
            const category = getEdgeCategory(rt);
            const refX = category === 'Contributor'
                ? CENTRAL_RADIUS + 6
                : rt === 'Created'
                    ? NODE_RADIUS + 6
                    : 28;
            defs.append('marker')
                .attr('id', `arrow-${rt}`)
                .attr('viewBox', '0 -5 10 10')
                .attr('refX', refX)
                .attr('refY', 0)
                .attr('markerWidth', 6)
                .attr('markerHeight', 6)
                .attr('orient', 'auto')
                .append('path')
                .attr('d', 'M0,-5L10,0L0,5')
                .attr('fill', getEdgeColor(rt));
        }

        // Drop shadow filter for central node
        const filter = defs.append('filter').attr('id', 'shadow');
        filter.append('feDropShadow')
            .attr('dx', 0)
            .attr('dy', 2)
            .attr('stdDeviation', 3)
            .attr('flood-opacity', 0.25);

        // Links
        const linkSelection = container.append('g')
            .attr('data-testid', 'graph-links')
            .selectAll<SVGLineElement, GraphLink>('line')
            .data(links)
            .join('line')
            .attr('stroke', (d) => getEdgeColor(d.relationType))
            .attr('stroke-width', 2)
            .attr('stroke-opacity', 0.7)
            .attr('marker-end', (d) => `url(#arrow-${d.relationType})`)
            .style('cursor', 'pointer')
            .on('mouseenter', function (event: MouseEvent, d) {
                select(this).attr('stroke-width', 4).attr('stroke-opacity', 1);
                const svgRect = svg.getBoundingClientRect();
                onTooltipChange({
                    visible: true,
                    x: event.clientX - svgRect.left,
                    y: event.clientY - svgRect.top,
                    content: {
                        label: d.relationLabel,
                        identifier: '',
                        identifierType: '',
                        relationType: d.relationType,
                    },
                    type: 'edge',
                });
            })
            .on('mouseleave', function () {
                select(this).attr('stroke-width', 2).attr('stroke-opacity', 0.7);
                hideTooltip();
            });

        // Node groups
        const nodeGroup = container.append('g')
            .attr('data-testid', 'graph-nodes')
            .selectAll<SVGGElement, GraphNode>('g')
            .data(nodes)
            .join('g')
            .style('cursor', (d) => (d.url ? 'pointer' : 'default'))
            .on('click', (_, d) => {
                if (d.url && !d.isCentral) {
                    onNodeClick(d);
                }
            })
            .on('mouseenter', function (event: MouseEvent, d) {
                select(this).select('circle').attr('stroke-width', 3);
                const svgRect = svg.getBoundingClientRect();
                onTooltipChange({
                    visible: true,
                    x: event.clientX - svgRect.left,
                    y: event.clientY - svgRect.top,
                    content: {
                        label: d.fullLabel,
                        identifier: d.identifier,
                        identifierType: d.identifierType,
                        relationType: d.isCentral ? undefined : d.relationType,
                        url: d.url,
                        nodeType: d.nodeType,
                        orcid: d.orcid,
                        contributorTypes: d.contributorTypes,
                    },
                    type: 'node',
                });
            })
            .on('mouseleave', function () {
                select(this).select('circle').attr('stroke-width', 1.5);
                hideTooltip();
            });

        // Circles
        nodeGroup.append('circle')
            .attr('r', (d) => getNodeRadius(d.nodeType, d.isCentral))
            .attr('fill', (d) => getNodeColor(d.identifierType, d.isCentral))
            .attr('stroke', '#fff')
            .attr('stroke-width', 1.5)
            .attr('filter', (d) => (d.isCentral ? 'url(#shadow)' : ''));

        // Labels
        nodeGroup.append('text')
            .text((d) => truncateLabel(d.label))
            .attr('text-anchor', 'middle')
            .attr('dy', (d) => getNodeRadius(d.nodeType, d.isCentral) + 16)
            .attr('font-size', '11px')
            .attr('fill', '#374151')
            .attr('pointer-events', 'none');

        // Force simulation
        const linkDistance = Math.max(100, Math.min(250, 600 / Math.sqrt(nodes.length)));

        const simulation = forceSimulation<GraphNode>(nodes)
            .force('link', forceLink<GraphNode, GraphLink>(links).id((d) => d.id).distance(linkDistance))
            .force('charge', forceManyBody().strength(-300))
            .force('center', forceCenter(width / 2, height / 2))
            .force('collide', forceCollide<GraphNode>().radius((d) => getNodeRadius(d.nodeType, d.isCentral) + 20))
            .alphaDecay(0.03)
            .velocityDecay(0.4);

        simulationRef.current = simulation;

        // Tick
        simulation.on('tick', () => {
            if (rafRef.current) return;
            rafRef.current = requestAnimationFrame(() => {
                rafRef.current = null;
                linkSelection
                    .attr('x1', (d) => (d.source as GraphNode).x ?? 0)
                    .attr('y1', (d) => (d.source as GraphNode).y ?? 0)
                    .attr('x2', (d) => (d.target as GraphNode).x ?? 0)
                    .attr('y2', (d) => (d.target as GraphNode).y ?? 0);

                nodeGroup.attr('transform', (d) => `translate(${d.x ?? 0},${d.y ?? 0})`);
            });
        });

        // Drag behavior
        const dragBehavior = drag<SVGGElement, GraphNode>()
            .on('start', (event, d) => {
                if (!event.active) simulation.alphaTarget(0.3).restart();
                d.fx = d.x;
                d.fy = d.y;
            })
            .on('drag', (event, d) => {
                d.fx = event.x;
                d.fy = event.y;
            })
            .on('end', (event, d) => {
                if (!event.active) simulation.alphaTarget(0);
                if (!d.isCentral) {
                    d.fx = null;
                    d.fy = null;
                }
            });

        nodeGroup.call(dragBehavior);

        // Keyboard accessibility for interactive nodes
        nodeGroup
            .filter((d: GraphNode) => !d.isCentral && !!d.url)
            .attr('tabindex', '0')
            .attr('role', 'link')
            .attr('aria-label', (d: GraphNode) => `${d.fullLabel} (${d.identifierType})`)
            .on('keydown', (event: KeyboardEvent, d: GraphNode) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onNodeClick(d);
                }
            })
            .on('focus', function () {
                select(this).select('circle').attr('stroke', '#2563eb').attr('stroke-width', 3);
            })
            .on('focusout', function () {
                select(this).select('circle').attr('stroke', '#fff').attr('stroke-width', 1.5);
            });

        // Zoom behavior
        const zoomBehavior = zoom<SVGSVGElement, unknown>()
            .scaleExtent([0.3, 3])
            .on('zoom', (event) => {
                container.attr('transform', event.transform);
                hideTooltip();
            });

        svgSelection.call(zoomBehavior);

        // Reduce motion
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReducedMotion) {
            simulation.alpha(0).stop();
            simulation.tick(300);
            linkSelection
                .attr('x1', (d) => (d.source as GraphNode).x ?? 0)
                .attr('y1', (d) => (d.source as GraphNode).y ?? 0)
                .attr('x2', (d) => (d.target as GraphNode).x ?? 0)
                .attr('y2', (d) => (d.target as GraphNode).y ?? 0);
            nodeGroup.attr('transform', (d) => `translate(${d.x ?? 0},${d.y ?? 0})`);
        }

        return () => {
            simulation.stop();
            if (rafRef.current) {
                cancelAnimationFrame(rafRef.current);
            }
        };
    }, [svgRef, nodes, links, width, height, onTooltipChange, onNodeClick, hideTooltip]);

    return { simulation: simulationRef.current };
}
