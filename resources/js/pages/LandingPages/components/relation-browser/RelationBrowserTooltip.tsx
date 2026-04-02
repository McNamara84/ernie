import { ExternalLink } from 'lucide-react';

import type { TooltipState } from './graph-types';

interface RelationBrowserTooltipProps {
    tooltip: TooltipState;
    containerRect: DOMRect | null;
}

function formatRelationType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

export function RelationBrowserTooltip({ tooltip, containerRect }: RelationBrowserTooltipProps) {
    if (!tooltip.visible || !containerRect) {
        return null;
    }

    const padding = 12;
    const tooltipWidth = 280;
    const tooltipHeight = tooltip.type === 'node' && (tooltip.content.nodeType === 'creator' || tooltip.content.nodeType === 'contributor' || tooltip.content.nodeType === 'institution') ? 140 : 100;

    let left = tooltip.x;
    let top = tooltip.y + 16;

    if (left + tooltipWidth > containerRect.width - padding) {
        left = containerRect.width - tooltipWidth - padding;
    }
    if (left < padding) {
        left = padding;
    }
    if (top + tooltipHeight > containerRect.height - padding) {
        top = tooltip.y - tooltipHeight - 8;
    }

    return (
        <div
            data-testid="relation-browser-tooltip"
            className="pointer-events-none absolute z-50 max-w-[280px] rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm shadow-lg transition-opacity duration-150"
            style={{ left, top }}
        >
            {tooltip.type === 'node' && tooltip.content.nodeType === 'creator' && (
                <div className="space-y-1">
                    <p className="font-medium text-gray-900 leading-snug">
                        {tooltip.content.label}
                    </p>
                    {tooltip.content.orcid ? (
                        <>
                            <p className="text-xs text-gray-500">
                                ORCID: {tooltip.content.orcid}
                            </p>
                            <p className="mt-1 flex items-center gap-1 text-xs text-blue-600">
                                <ExternalLink className="h-3 w-3" />
                                Click to open ORCID profile
                            </p>
                        </>
                    ) : (
                        <p className="text-xs text-gray-400">No ORCID available</p>
                    )}
                </div>
            )}
            {tooltip.type === 'node' && tooltip.content.nodeType === 'contributor' && (
                <div className="space-y-1">
                    <p className="font-medium text-gray-900 leading-snug">
                        {tooltip.content.label}
                    </p>
                    {tooltip.content.contributorTypes && tooltip.content.contributorTypes.length > 0 && (
                        <p className="text-xs text-gray-500">
                            {tooltip.content.contributorTypes.join(', ')}
                        </p>
                    )}
                    {tooltip.content.orcid ? (
                        <>
                            <p className="text-xs text-gray-500">
                                ORCID: {tooltip.content.orcid}
                            </p>
                            <p className="mt-1 flex items-center gap-1 text-xs text-blue-600">
                                <ExternalLink className="h-3 w-3" />
                                Click to open ORCID profile
                            </p>
                        </>
                    ) : (
                        <p className="text-xs text-gray-400">No ORCID available</p>
                    )}
                </div>
            )}
            {tooltip.type === 'node' && tooltip.content.nodeType === 'institution' && (
                <div className="space-y-1">
                    <p className="font-medium text-gray-900 leading-snug">
                        {tooltip.content.label}
                    </p>
                    {tooltip.content.rorId ? (
                        <>
                            <p className="text-xs text-gray-500">
                                ROR: {tooltip.content.rorId}
                            </p>
                            <p className="mt-1 flex items-center gap-1 text-xs text-blue-600">
                                <ExternalLink className="h-3 w-3" />
                                Click to open ROR entry
                            </p>
                        </>
                    ) : (
                        <p className="text-xs text-gray-400">No ROR ID available</p>
                    )}
                </div>
            )}
            {tooltip.type === 'node' && tooltip.content.nodeType !== 'creator' && tooltip.content.nodeType !== 'contributor' && tooltip.content.nodeType !== 'institution' && (
                <div className="space-y-1">
                    <p className="font-medium text-gray-900 leading-snug">
                        {tooltip.content.label}
                    </p>
                    <p className="text-xs text-gray-500">
                        {tooltip.content.identifierType}: {tooltip.content.identifier}
                    </p>
                    {tooltip.content.relationType && (
                        <p className="text-xs text-gray-500">
                            {formatRelationType(tooltip.content.relationType)}
                        </p>
                    )}
                    {tooltip.content.url && (
                        <p className="mt-1 flex items-center gap-1 text-xs text-blue-600">
                            <ExternalLink className="h-3 w-3" />
                            Click to open
                        </p>
                    )}
                </div>
            )}
            {tooltip.type === 'edge' && (
                <p className="font-medium text-gray-900">
                    {formatRelationType(tooltip.content.relationType ?? '')}
                </p>
            )}
        </div>
    );
}
