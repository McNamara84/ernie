import * as d3 from 'd3';
import { type RefObject, useCallback, useEffect, useRef } from 'react';

import { getEdgeColor, getNodeColor } from './graph-colors';
import type { GraphLink, GraphNode, TooltipState } from './graph-types';
import { truncateLabel } from './graph-utils';

interface UseRelationGraphOptions {
    width: number;
    height: number;
    onTooltipChange: (tooltip: TooltipState) => void;
    onNodeClick: (node: GraphNode) => void;
}

const CENTRAL_RADIUS = 30;
const NODE_RADIUS = 22;

export function useRelationGraph(
    svgRef: RefObject<SVGSVGElement | null>,
    nodes: GraphNode[],
    links: GraphLink[],
    options: UseRelationGraphOptions,
) {
    const simulationRef = useRef<d3.Simulation<GraphNode, GraphLink> | null>(null);
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
        d3.select(svg).selectAll('*').remove();
        simulationRef.current?.stop();

        const svgSelection = d3.select(svg);

        // Container group for zoom/pan
        const container = svgSelection.append('g').attr('data-testid', 'graph-container');

        // Arrow marker definition
        const defs = svgSelection.append('defs');
        const markerTypes = [...new Set(links.map((l) => l.relationType))];
        for (const rt of markerTypes) {
            defs.append('marker')
                .attr('id', `arrow-${rt}`)
                .attr('viewBox', '0 -5 10 10')
                .attr('refX', 28)
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
                d3.select(this).attr('stroke-width', 4).attr('stroke-opacity', 1);
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
                d3.select(this).attr('stroke-width', 2).attr('stroke-opacity', 0.7);
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
                d3.select(this).select('circle').attr('stroke-width', 3);
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
                    },
                    type: 'node',
                });
            })
            .on('mouseleave', function () {
                d3.select(this).select('circle').attr('stroke-width', 1.5);
                hideTooltip();
            });

        // Circles
        nodeGroup.append('circle')
            .attr('r', (d) => (d.isCentral ? CENTRAL_RADIUS : NODE_RADIUS))
            .attr('fill', (d) => getNodeColor(d.identifierType, d.isCentral))
            .attr('stroke', '#fff')
            .attr('stroke-width', 1.5)
            .attr('filter', (d) => (d.isCentral ? 'url(#shadow)' : ''));

        // Labels
        nodeGroup.append('text')
            .text((d) => truncateLabel(d.label))
            .attr('text-anchor', 'middle')
            .attr('dy', (d) => (d.isCentral ? CENTRAL_RADIUS + 16 : NODE_RADIUS + 16))
            .attr('font-size', '11px')
            .attr('fill', '#374151')
            .attr('pointer-events', 'none');

        // Force simulation
        const linkDistance = Math.max(100, Math.min(250, 600 / Math.sqrt(nodes.length)));

        const simulation = d3.forceSimulation<GraphNode>(nodes)
            .force('link', d3.forceLink<GraphNode, GraphLink>(links).id((d) => d.id).distance(linkDistance))
            .force('charge', d3.forceManyBody().strength(-300))
            .force('center', d3.forceCenter(width / 2, height / 2))
            .force('collide', d3.forceCollide<GraphNode>().radius((d) => (d.isCentral ? CENTRAL_RADIUS + 20 : NODE_RADIUS + 20)))
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
        const drag = d3.drag<SVGGElement, GraphNode>()
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

        nodeGroup.call(drag);

        // Zoom behavior
        const zoom = d3.zoom<SVGSVGElement, unknown>()
            .scaleExtent([0.3, 3])
            .on('zoom', (event) => {
                container.attr('transform', event.transform);
                hideTooltip();
            });

        svgSelection.call(zoom);

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
