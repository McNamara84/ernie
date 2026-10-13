import { Link } from '@inertiajs/react';

import { relationCorrectionMetadata } from '@/lib/relation-type-correction';
import { isSafeHttpUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';
import { editor } from '@/routes';
import type { BaseSuggestionItem } from '@/types/assistance';

export function RelationTypeCorrectionCard({ suggestion }: { suggestion: BaseSuggestionItem }) {
    const metadata = relationCorrectionMetadata(suggestion);
    if (!metadata) return <p className="p-3 text-sm">This proposal is incomplete. Run the relation type check again.</p>;
    return (
        <div className="space-y-3 p-3 text-sm" data-testid={`relation-type-correction-${suggestion.id}`}>
            <p className="font-medium break-all">{metadata.current.identifier}</p>
            {metadata.current.citation_label && <p className="text-muted-foreground">{metadata.current.citation_label}</p>}
            <p>Read the relationship from this resource ({suggestion.resource_doi}) to the related identifier.</p>
            <dl className="grid grid-cols-2 gap-2 rounded border p-3">
                <div>
                    <dt className="text-muted-foreground">Current relation type</dt>
                    <dd>
                        {metadata.current.relation_type_name} ({metadata.current.relation_type})
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Proposed relation type</dt>
                    <dd>
                        {metadata.proposed.name} ({metadata.proposed.slug})
                    </dd>
                </div>
            </dl>
            <p>{metadata.rule.rationale}</p>
            <p>
                <strong>High confidence</strong> — {metadata.confidence.basis}
            </p>
            <details>
                <summary className="cursor-pointer">Evidence and provenance</summary>
                <ul className="mt-2 space-y-2">
                    {metadata.evidence.map((claim, index) => (
                        <li key={index} className="rounded border p-2 break-all">
                            <p>
                                {claim.subject} → {claim.relation} → {claim.object}
                            </p>
                            <p>
                                {claim.provider}: asserted by {claim.claimant}. {claim.primary ? 'Primary assertion' : 'Supporting evidence'}.
                            </p>
                            <p>
                                Source relation: {claim.original_relation}. Retrieved: {claim.fetched_at}.
                            </p>
                            {isSafeHttpUrl(claim.source_url) && (
                                <a className="text-primary underline" href={claim.source_url} target="_blank" rel="noopener noreferrer">
                                    View source metadata
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            </details>
            <p>Acceptance changes this relation type and records your review. Each resource is reviewed independently.</p>
            <Link className="text-primary underline" href={editor({ query: { resourceId: suggestion.resource_id } }).url}>
                Open resource editor
            </Link>
        </div>
    );
}
