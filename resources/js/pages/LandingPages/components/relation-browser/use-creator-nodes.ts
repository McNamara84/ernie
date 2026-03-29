import { useEffect, useRef, useState } from 'react';

import type { LandingPageCreator, LandingPageRelatedIdentifier, LandingPageResource } from '@/types/landing-page';

import { normalizeDoiKey } from '../../lib/resolveIdentifierUrl';

import type { GraphLink, GraphNode } from './graph-types';

interface CreatorInfo {
    givenName: string | null;
    familyName: string | null;
    institutionName: string | null;
    orcid: string | null;
    datasetNodeIds: Set<string>;
}

interface ApiAuthor {
    given_name: string | null;
    family_name: string | null;
    name: string | null;
    orcid: string | null;
}

interface UseCreatorNodesResult {
    creatorNodes: GraphNode[];
    creatorLinks: GraphLink[];
    loading: boolean;
}

/**
 * Normalize a name for deduplication: lowercase, trimmed.
 */
function normalizeNameKey(familyName: string | null, givenName: string | null): string {
    const family = (familyName ?? '').trim().toLowerCase();
    const given = (givenName ?? '').trim().toLowerCase();
    return `${family}|${given}`;
}

/**
 * Build a display label from creator name parts: "FamilyName, GivenName".
 */
function buildCreatorLabel(info: CreatorInfo): string {
    if (info.familyName && info.givenName) {
        return `${info.familyName}, ${info.givenName}`;
    }
    if (info.familyName) {
        return info.familyName;
    }
    if (info.institutionName) {
        return info.institutionName;
    }
    return 'Unknown';
}

/**
 * Build a stable node ID for a creator.
 */
function buildCreatorId(info: CreatorInfo): string {
    if (info.orcid) {
        return `creator-${info.orcid}`;
    }
    const nameKey = normalizeNameKey(info.familyName, info.givenName);
    if (nameKey !== '|') {
        return `creator-${nameKey}`;
    }
    if (info.institutionName) {
        return `creator-${info.institutionName.trim().toLowerCase()}`;
    }
    return `creator-unknown-${Math.random().toString(36).slice(2, 8)}`;
}

/**
 * Build ORCID profile URL from an ORCID identifier.
 */
function buildOrcidUrl(orcid: string | null): string | null {
    if (!orcid) return null;
    return `https://orcid.org/${orcid}`;
}

/**
 * Merge a creator into the deduplication map.
 * Deduplicates by ORCID first, then by normalized name.
 */
function mergeCreator(
    map: Map<string, CreatorInfo>,
    orcidIndex: Map<string, string>,
    creator: { givenName: string | null; familyName: string | null; institutionName: string | null; orcid: string | null },
    datasetNodeId: string,
): void {
    // Try to find existing entry by ORCID
    if (creator.orcid) {
        const existingKey = orcidIndex.get(creator.orcid);
        if (existingKey) {
            map.get(existingKey)!.datasetNodeIds.add(datasetNodeId);
            return;
        }
    }

    // Try to find existing entry by normalized name
    const nameKey = normalizeNameKey(creator.familyName, creator.givenName);
    if (nameKey !== '|') {
        const existingByName = map.get(nameKey);
        if (existingByName) {
            existingByName.datasetNodeIds.add(datasetNodeId);
            // Upgrade ORCID if this entry has one and existing doesn't
            if (creator.orcid && !existingByName.orcid) {
                existingByName.orcid = creator.orcid;
                orcidIndex.set(creator.orcid, nameKey);
            }
            return;
        }
    }

    // New creator
    const info: CreatorInfo = {
        givenName: creator.givenName,
        familyName: creator.familyName,
        institutionName: creator.institutionName,
        orcid: creator.orcid,
        datasetNodeIds: new Set([datasetNodeId]),
    };

    const key = nameKey !== '|' ? nameKey : `inst-${(creator.institutionName ?? 'unknown').trim().toLowerCase()}`;
    map.set(key, info);
    if (creator.orcid) {
        orcidIndex.set(creator.orcid, key);
    }
}

/**
 * Extract creator info from a LandingPageCreator.
 */
function fromLandingPageCreator(creator: LandingPageCreator): {
    givenName: string | null;
    familyName: string | null;
    institutionName: string | null;
    orcid: string | null;
} {
    const c = creator.creatorable;
    let orcid: string | null = null;
    if (c.name_identifier_scheme === 'ORCID' && c.name_identifier) {
        const match = c.name_identifier.match(/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/);
        orcid = match ? match[1] : null;
    }

    return {
        givenName: c.given_name,
        familyName: c.family_name,
        institutionName: c.name,
        orcid,
    };
}

/**
 * Extract creator info from an API author response.
 */
function fromApiAuthor(author: ApiAuthor): {
    givenName: string | null;
    familyName: string | null;
    institutionName: string | null;
    orcid: string | null;
} {
    return {
        givenName: author.given_name,
        familyName: author.family_name,
        institutionName: author.name,
        orcid: author.orcid,
    };
}

/**
 * Hook that fetches and deduplicates creator data for the Relation Browser graph.
 *
 * Central resource creators come from Inertia props (immediate).
 * Related DOI creators are fetched asynchronously via /api/datacite/authors/{doi}.
 * Creators are deduplicated by ORCID first, then by normalized name.
 */
export function useCreatorNodes(
    resource: LandingPageResource,
    relatedIdentifiers: LandingPageRelatedIdentifier[],
): UseCreatorNodesResult {
    const [apiAuthors, setApiAuthors] = useState<Map<string, ApiAuthor[]>>(new Map());
    const [loading, setLoading] = useState(false);
    const controllerRef = useRef<AbortController | null>(null);

    // Fetch authors for related DOIs
    useEffect(() => {
        controllerRef.current?.abort();
        const controller = new AbortController();
        controllerRef.current = controller;

        const doisToFetch = relatedIdentifiers
            .filter((rel) => rel.identifier_type === 'DOI')
            .map((rel) => ({
                nodeId: `related-${rel.id}`,
                doi: normalizeDoiKey(rel.identifier),
            }))
            .filter((item) => item.doi !== '');

        if (doisToFetch.length === 0) {
            setLoading(false);
            return () => controller.abort();
        }

        setLoading(true);
        let pending = doisToFetch.length;

        for (const item of doisToFetch) {
            fetch(`/api/datacite/authors/${encodeURIComponent(item.doi)}`, {
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) throw new Error('Not found');
                    return response.json();
                })
                .then((data: { doi: string; authors?: ApiAuthor[] }) => {
                    const authors = Array.isArray(data.authors) ? data.authors : [];
                    setApiAuthors((prev) => {
                        const next = new Map(prev);
                        next.set(item.nodeId, authors);
                        return next;
                    });
                })
                .catch((err: unknown) => {
                    if (err instanceof Error && err.name === 'AbortError') return;
                    // Silently skip DOIs where authors can't be fetched
                })
                .finally(() => {
                    pending--;
                    if (pending <= 0) {
                        setLoading(false);
                    }
                });
        }

        return () => controller.abort();
    }, [relatedIdentifiers]);

    // Build deduplicated creator nodes and links
    const creatorMap = new Map<string, CreatorInfo>();
    const orcidIndex = new Map<string, string>();

    // 1. Central resource creators (immediate)
    const centralCreators = resource.creators ?? [];
    for (const creator of centralCreators) {
        mergeCreator(creatorMap, orcidIndex, fromLandingPageCreator(creator), 'central');
    }

    // 2. Related DOI creators (async)
    for (const [nodeId, authors] of apiAuthors) {
        for (const author of authors) {
            mergeCreator(creatorMap, orcidIndex, fromApiAuthor(author), nodeId);
        }
    }

    // Build graph nodes
    const creatorNodes: GraphNode[] = [];
    const creatorLinks: GraphLink[] = [];

    for (const info of creatorMap.values()) {
        const nodeId = buildCreatorId(info);
        const label = buildCreatorLabel(info);
        const orcidUrl = buildOrcidUrl(info.orcid);

        creatorNodes.push({
            id: nodeId,
            label,
            fullLabel: label,
            identifier: info.orcid ?? label,
            identifierType: 'Creator',
            relationType: 'Created',
            url: orcidUrl,
            isCentral: false,
            nodeType: 'creator',
            orcid: info.orcid,
        });

        for (const datasetNodeId of info.datasetNodeIds) {
            creatorLinks.push({
                source: nodeId,
                target: datasetNodeId,
                relationType: 'Created',
                relationLabel: 'created',
            });
        }
    }

    return { creatorNodes, creatorLinks, loading };
}

// Export helpers for testing
export { normalizeNameKey, buildCreatorLabel, buildCreatorId, mergeCreator, fromLandingPageCreator, fromApiAuthor };
export type { CreatorInfo, ApiAuthor };
