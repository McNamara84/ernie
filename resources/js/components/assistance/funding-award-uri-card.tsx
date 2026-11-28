import { Link } from '@inertiajs/react';

import { awardUriMetadata } from '@/lib/funding-award-uri';
import { isSafeHttpUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';
import { editor } from '@/routes';
import type { BaseSuggestionItem } from '@/types/assistance';

export function FundingAwardUriCard({ suggestion }: { suggestion: BaseSuggestionItem }) {
    const metadata = awardUriMetadata(suggestion);
    if (!metadata) return <p role="alert">Award evidence is incomplete. Run Check again before reviewing this suggestion.</p>;
    const current = metadata.live_current ?? metadata.current;
    return (
        <div className="space-y-3 text-sm" data-testid="funding-award-uri-preview">
            {metadata.live_current === null && <p>The funding reference no longer exists. The values below were recorded at discovery.</p>}
            <p>
                <strong>Funder:</strong> {current.funder_name} ({current.funder_identifier})
            </p>
            <p>
                <strong>Award number:</strong> {current.award_number}
            </p>
            <p>
                <strong>Award title:</strong> {current.award_title || 'Not recorded'}
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <strong>{metadata.live_current === null ? 'Award URI at discovery' : 'Current award URI'}</strong>
                    <p className="break-all">{current.award_uri?.trim() || 'Empty'}</p>
                </div>
                <div>
                    <strong>Proposed award URI</strong>
                    <p className="break-all">
                        {isSafeHttpUrl(metadata.proposed.award_uri) ? (
                            <a className="text-primary underline" href={metadata.proposed.award_uri} target="_blank" rel="noopener noreferrer">
                                {metadata.proposed.award_uri}
                            </a>
                        ) : (
                            metadata.proposed.award_uri
                        )}
                    </p>
                </div>
            </div>
            <p>High confidence: exact award number, verified funder and one unambiguous award.</p>
            {metadata.review_status && (
                <p role="alert">This suggestion cannot be accepted ({metadata.review_status.replaceAll('_', ' ')}). Run Check again.</p>
            )}
            <details>
                <summary className="cursor-pointer">Evidence and provenance</summary>
                <ul className="mt-2 space-y-2">
                    {metadata.provenance.map((source, index) => (
                        <li key={index} className="rounded border p-2 break-all">
                            <p>
                                {source.provider === 'crossref' ? 'Crossref Grants' : 'CORDIS'}: {source.record_id}
                            </p>
                            <p>
                                Award: {source.award_number}. {source.titles.join('; ')}
                            </p>
                            <p>
                                Funder identifiers: {source.funder_ids.join(', ') || 'Verified through funding programme'}. Programmes:{' '}
                                {source.programmes.join(', ') || 'Not applicable'}.
                            </p>
                            <p>Retrieved: {source.fetched_at}</p>
                            {isSafeHttpUrl(source.source_url) && (
                                <a className="text-primary underline" href={source.source_url} target="_blank" rel="noopener noreferrer">
                                    View source metadata
                                </a>
                            )}
                            <p>
                                {source.attribution}{' '}
                                {isSafeHttpUrl(source.license_url) && (
                                    <a className="text-primary underline" href={source.license_url} target="_blank" rel="noopener noreferrer">
                                        Reuse terms
                                    </a>
                                )}
                            </p>
                        </li>
                    ))}
                </ul>
            </details>
            <p>Acceptance fills this empty award URI. Your decision, the source evidence and the previous value are retained.</p>
            <p>For registered resources, accepted changes also follow the existing DataCite synchronization workflow.</p>
            <Link className="text-primary underline" href={editor({ query: { resourceId: suggestion.resource_id } }).url}>
                Open resource editor
            </Link>
        </div>
    );
}
