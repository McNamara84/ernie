import type { SimulationLinkDatum, SimulationNodeDatum } from 'd3-force';

export interface GraphNode extends SimulationNodeDatum {
    id: string;
    label: string;
    fullLabel: string;
    identifier: string;
    identifierType: string;
    relationType: string;
    url: string | null;
    isCentral: boolean;
}

export interface GraphLink extends SimulationLinkDatum<GraphNode> {
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
    };
    type: 'node' | 'edge';
}

export interface CitationLabel {
    shortLabel: string;
    fullCitation: string;
    loading: boolean;
}
