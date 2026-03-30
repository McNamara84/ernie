import { useMemo, useState } from 'react';

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
    citationTexts?: Map<string, string>;
}

export function RelationBrowserModal({
    open,
    onOpenChange,
    resource,
    relatedIdentifiers,
    citationTexts,
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

    // Receive hasCreators/hasContributors from RelationBrowserGraph to avoid duplicate hook calls
    const [hasCreators, setHasCreators] = useState(false);
    const [hasContributors, setHasContributors] = useState(false);

    // Build deduplicated identifier types including Creator/Contributor
    const allIdentifierTypes = useMemo(() => {
        const types = new Set(activeIdentifierTypes);
        if (hasCreators) types.add('Creator');
        if (hasContributors) types.add('Contributor');
        return [...types];
    }, [activeIdentifierTypes, hasCreators, hasContributors]);

    // Collect active relation types including person role types
    const personRelationTypes = useMemo(() => {
        const types = new Set(activeRelationTypes);
        if (hasCreators) types.add('Created');
        if (hasContributors) {
            const contributorTypes = (resource.contributors ?? []).flatMap((c) => c.contributor_types);
            for (const ct of contributorTypes) {
                types.add(ct);
            }
        }
        return [...types];
    }, [activeRelationTypes, hasCreators, hasContributors, resource.contributors]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl"
                style={{ display: 'flex' }}
                data-testid="relation-browser-modal"
            >
                <DialogHeader className="shrink-0 border-b border-gray-200 px-6 py-4">
                    <DialogTitle>Relation Browser</DialogTitle>
                    <DialogDescription>
                        Interactive graph of relationships between this resource, related works, their creators, and contributors.
                        Hover over nodes and edges for details. Click on a node to open the related resource or ORCID profile.
                    </DialogDescription>
                </DialogHeader>

                {/* Graph area */}
                <div className="relative min-h-0 flex-1 overflow-hidden">
                    {open && (
                        <RelationBrowserGraph
                            resource={resource}
                            relatedIdentifiers={renderableIdentifiers}
                            citationTexts={citationTexts}
                            onPersonNodesChange={(creators, contributors) => {
                                setHasCreators(creators);
                                setHasContributors(contributors);
                            }}
                        />
                    )}
                </div>

                {/* Legend */}
                <div className="shrink-0">
                    <RelationBrowserLegend
                        activeIdentifierTypes={allIdentifierTypes}
                        activeRelationTypes={personRelationTypes}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
