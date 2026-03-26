import type * as d3 from 'd3';

export interface GraphNode extends d3.SimulationNodeDatum {
    id: string;
    label: string;
    fullLabel: string;
    identifier: string;
    identifierType: string;
    relationType: string;
    url: string | null;
    isCentral: boolean;
}

export interface GraphLink extends d3.SimulationLinkDatum<GraphNode> {
    source: string | GraphNode;
    target: string | GraphNode;
    relationType: string;
    relationLabel: string;
}

export interface TooltipState {
    visible: boolean;
    x: number;
    y: number;
    content: {
        label: string;
        identifier: string;
        identifierType: string;
        relationType?: string;
        url?: string | null;
        loading?: boolean;
    };
    type: 'node' | 'edge';
}

export interface CitationLabel {
    shortLabel: string;
    fullCitation: string;
    loading: boolean;
}
