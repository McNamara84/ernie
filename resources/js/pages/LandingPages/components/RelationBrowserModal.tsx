import { useMemo } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

import { resolveIdentifierUrl } from '../lib/resolveIdentifierUrl';

import { RelationBrowserGraph } from './relation-browser/RelationBrowserGraph';
import { RelationBrowserLegend } from './relation-browser/RelationBrowserLegend';

interface RelationBrowserModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    resource: LandingPageResource;
    relatedIdentifiers: LandingPageRelatedIdentifier[];
}

export function RelationBrowserModal({
    open,
    onOpenChange,
    resource,
    relatedIdentifiers,
}: RelationBrowserModalProps) {
    // Only include identifiers that have resolvable URLs
    const renderableIdentifiers = useMemo(
        () => relatedIdentifiers.filter(
            (rel) => resolveIdentifierUrl(rel.identifier, rel.identifier_type) !== null,
        ),
        [relatedIdentifiers],
    );

    const activeIdentifierTypes = useMemo(
        () => [...new Set(renderableIdentifiers.map((r) => r.identifier_type))],
        [renderableIdentifiers],
    );

    const activeRelationTypes = useMemo(
        () => [...new Set(renderableIdentifiers.map((r) => r.relation_type))],
        [renderableIdentifiers],
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex h-[85vh] max-w-6xl flex-col gap-0 p-0"
                data-testid="relation-browser-modal"
            >
                <DialogHeader className="shrink-0 border-b border-gray-200 px-6 py-4">
                    <DialogTitle>Relation Browser</DialogTitle>
                    <DialogDescription>
                        Interactive graph of relationships between this resource and related works.
                        Hover over nodes and edges for details. Click on a node to open the related resource.
                    </DialogDescription>
                </DialogHeader>

                {/* Graph area */}
                <div className="min-h-0 flex-1">
                    {open && (
                        <RelationBrowserGraph
                            resource={resource}
                            relatedIdentifiers={renderableIdentifiers}
                        />
                    )}
                </div>

                {/* Legend */}
                <div className="shrink-0">
                    <RelationBrowserLegend
                        activeIdentifierTypes={activeIdentifierTypes}
                        activeRelationTypes={activeRelationTypes}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
