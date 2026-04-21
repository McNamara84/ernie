import { useEffect, useMemo, useRef, useState } from 'react';

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

interface ApiAffiliation {
    name: string;
    identifier: string | null;
    identifier_scheme: string | null;
}

interface ApiAuthor {
    given_name: string | null;
    family_name: string | null;
    name: string | null;
    orcid: string | null;
    type?: 'Person' | 'Institution';
    affiliations?: ApiAffiliation[];
    ror_id?: string | null;
}

/**
 * Extended API author type that includes affiliations (from DataCite REST API).
 */
export interface ApiAuthorWithAffiliations {
    given_name: string | null;
    family_name: string | null;
    name: string | null;
    orcid: string | null;
    type?: 'Person' | 'Institution';
    affiliations?: ApiAffiliation[];
    ror_id?: string | null;
}

interface UseCreatorNodesResult {
    creatorNodes: GraphNode[];
    creatorLinks: GraphLink[];
    creatorNodeIdMap: Map<string, string>;
    apiAuthorsWithAffiliations: Map<string, ApiAuthorWithAffiliations[]>;
    loading: boolean;
}

interface Counter {
    value: number;
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
function buildCreatorId(info: CreatorInfo, counter: Counter): string {
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
    return `creator-unknown-${counter.value++}`;
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
    counter: Counter,
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

    const key = nameKey !== '|'
        ? nameKey
        : creator.institutionName
            ? `inst-${creator.institutionName.trim().toLowerCase()}`
            : `unknown-${counter.value++}`;
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
 * Related DOI creators are fetched asynchronously via /api/datacite/authors?doi=...
 * Creators are deduplicated by ORCID first, then by normalized name.
 */
export function useCreatorNodes(
    resource: LandingPageResource,
    relatedIdentifiers: LandingPageRelatedIdentifier[],
): UseCreatorNodesResult {
    const [apiAuthors, setApiAuthors] = useState<Map<string, ApiAuthor[]>>(new Map());
    const [loading, setLoading] = useState(false);
    const controllerRef = useRef<AbortController | null>(null);
    const requestIdRef = useRef(0);

    // Fetch authors for all related DOIs in a single batch
    useEffect(() => {
        controllerRef.current?.abort();
        const controller = new AbortController();
        controllerRef.current = controller;
        const currentRequestId = ++requestIdRef.current;

        const doisToFetch = relatedIdentifiers
            .filter((rel) => rel.identifier_type === 'DOI')
            .map((rel) => ({
                nodeId: `related-${rel.id}`,
                doi: normalizeDoiKey(rel.identifier),
            }))
            .filter((item) => item.doi !== '');

        // Group nodeIds by normalized DOI to avoid duplicate requests
        const doiToNodeIds = new Map<string, string[]>();
        for (const item of doisToFetch) {
            const existing = doiToNodeIds.get(item.doi);
            if (existing) {
                existing.push(item.nodeId);
            } else {
                doiToNodeIds.set(item.doi, [item.nodeId]);
            }
        }

        const uniqueDois = [...doiToNodeIds.keys()];
        if (uniqueDois.length === 0) {
            setApiAuthors((prev) => (prev.size === 0 ? prev : new Map()));
            setLoading(false);
            return () => controller.abort();
        }

        setLoading(true);

        // Batch all fetches with Promise.allSettled → single state update
        const fetchPromises = uniqueDois.map((doi) =>
            fetch(`/api/datacite/authors?doi=${encodeURIComponent(doi)}`, {
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) throw new Error('Not found');
                    return response.json();
                })
                .then((data: { doi: string; authors?: ApiAuthor[] }) => ({
                    doi,
                    authors: Array.isArray(data.authors) ? data.authors : [],
                })),
        );

        Promise.allSettled(fetchPromises)
            .then((results) => {
                if (currentRequestId !== requestIdRef.current) return;
                const next = new Map<string, ApiAuthor[]>();
                for (const result of results) {
                    if (result.status !== 'fulfilled') continue;
                    const { doi, authors } = result.value;
                    const nodeIds = doiToNodeIds.get(doi)!;
                    for (const nodeId of nodeIds) {
                        next.set(nodeId, authors);
                    }
                }
                setApiAuthors(next);
                setLoading(false);
            })
            .catch(() => {
                // AbortError or unexpected — ignore
            });

        return () => controller.abort();
    }, [relatedIdentifiers]);

    // Build deduplicated creator nodes and links (memoized to prevent simulation restarts)
    const { creatorNodes, creatorLinks, creatorNodeIdMap, apiAuthorsWithAffiliations } = useMemo(() => {
        const creatorMap = new Map<string, CreatorInfo>();
        const orcidIndex = new Map<string, string>();
        const counter: Counter = { value: 0 };

        // 1. Central resource creators (immediate) — skip institutions
        const centralCreators = resource.creators ?? [];
        for (const creator of centralCreators) {
            if (creator.creatorable.type === 'Institution') continue;
            mergeCreator(creatorMap, orcidIndex, fromLandingPageCreator(creator), 'central', counter);
        }

        // 2. Related DOI creators (async) — skip institutions
        for (const [nodeId, authors] of apiAuthors) {
            for (const author of authors) {
                if (author.type === 'Institution') continue;
                mergeCreator(creatorMap, orcidIndex, fromApiAuthor(author), nodeId, counter);
            }
        }

        // Build graph nodes
        const nodes: GraphNode[] = [];
        const links: GraphLink[] = [];
        const nodeIdMap = new Map<string, string>();
        const idCounter: Counter = { value: 0 };

        for (const [, info] of creatorMap) {
            const nodeId = buildCreatorId(info, idCounter);
            const label = buildCreatorLabel(info);
            const orcidUrl = buildOrcidUrl(info.orcid);

            // Build lookup map keyed by ORCID and name key
            if (info.orcid) {
                nodeIdMap.set(info.orcid, nodeId);
            }
            const nameKey = normalizeNameKey(info.familyName, info.givenName);
            if (nameKey !== '|') {
                nodeIdMap.set(nameKey, nodeId);
            }

            nodes.push({
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
                links.push({
                    source: nodeId,
                    target: datasetNodeId,
                    relationType: 'Created',
                    relationLabel: 'created',
                });
            }
        }

        return { creatorNodes: nodes, creatorLinks: links, creatorNodeIdMap: nodeIdMap, apiAuthorsWithAffiliations: apiAuthors as Map<string, ApiAuthorWithAffiliations[]> };
    }, [resource.creators, apiAuthors]);

    return { creatorNodes, creatorLinks, creatorNodeIdMap, apiAuthorsWithAffiliations, loading };
}

// Export helpers for testing
export { buildCreatorId, buildCreatorLabel, fromApiAuthor,fromLandingPageCreator, mergeCreator, normalizeNameKey };
export type { ApiAffiliation,ApiAuthor, CreatorInfo };
