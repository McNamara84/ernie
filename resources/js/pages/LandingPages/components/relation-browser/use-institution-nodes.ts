import { useMemo } from 'react';

import type {
    LandingPageContributor,
    LandingPageCreator,
    LandingPageResource,
} from '@/types/landing-page';

import type { GraphLink, GraphNode } from './graph-types';
import { truncateLabel } from './graph-utils';
import type { ApiAuthorWithAffiliations } from './use-creator-nodes';

/**
 * Information about a deduplicated institution.
 */
interface InstitutionInfo {
    name: string;
    rorId: string | null;
    rorUrl: string | null;
    /** Maps connected node IDs to their edge metadata */
    connectedNodeIds: Map<string, EdgeInfo>;
}

/**
 * Metadata for a single edge between an institution and a connected node.
 */
interface EdgeInfo {
    /** Human-readable display label for the edge (e.g. "Affiliated with", "Data Collector") */
    edgeLabel: string;
    /** PascalCase relation type for edge categorization and coloring (e.g. "AffiliatedWith", "Created", "DataCollector") */
    relationType: string;
}

interface UseInstitutionNodesResult {
    institutionNodes: GraphNode[];
    institutionLinks: GraphLink[];
}

/**
 * Resolve a ROR identifier (full URL or bare ID) into a canonical ROR URL.
 */
export function resolveRorUrl(identifier: string | null | undefined): string | null {
    if (!identifier) return null;
    // Already a full URL
    if (identifier.startsWith('https://ror.org/')) return identifier;
    // Bare ROR ID (e.g., "04z8jg394")
    if (/^0[a-z0-9]{6}\d{2}$/.test(identifier)) return `https://ror.org/${identifier}`;
    return null;
}

/**
 * Extract the bare ROR ID from a URL or identifier string.
 */
export function extractRorId(identifier: string | null | undefined): string | null {
    if (!identifier) return null;
    const match = identifier.match(/(0[a-z0-9]{6}\d{2})/);
    return match ? match[1] : null;
}

/**
 * Build a deduplication key for an institution.
 * Priority: ROR ID → normalized name.
 */
export function getInstitutionKey(name: string, rorId: string | null): string {
    if (rorId) {
        return `ror-${rorId}`;
    }
    return `name-${name.trim().toLowerCase()}`;
}

/**
 * Merge an institution into the deduplication map.
 */
function mergeInstitution(
    map: Map<string, InstitutionInfo>,
    rorIndex: Map<string, string>,
    name: string,
    rorIdentifier: string | null | undefined,
    connectedNodeId: string,
    edge: EdgeInfo,
): void {
    const rorId = extractRorId(rorIdentifier ?? null);
    const rorUrl = resolveRorUrl(rorIdentifier ?? null);

    // Try finding existing entry by ROR ID
    if (rorId) {
        const existingKey = rorIndex.get(rorId);
        if (existingKey) {
            const existing = map.get(existingKey)!;
            existing.connectedNodeIds.set(connectedNodeId, edge);
            return;
        }
    }

    // Try finding existing entry by normalized name
    const nameKey = `name-${name.trim().toLowerCase()}`;
    const existing = map.get(nameKey);
    if (existing) {
        existing.connectedNodeIds.set(connectedNodeId, edge);
        // Upgrade ROR if this instance has one and existing doesn't
        if (rorId && !existing.rorId) {
            existing.rorId = rorId;
            existing.rorUrl = rorUrl;
            rorIndex.set(rorId, nameKey);
        }
        return;
    }

    // Also check if there's already a ror-keyed entry with matching name
    if (rorId) {
        const rorKey = `ror-${rorId}`;
        const existingByRor = map.get(rorKey);
        if (existingByRor) {
            existingByRor.connectedNodeIds.set(connectedNodeId, edge);
            return;
        }
    }

    // New institution
    const key = rorId ? `ror-${rorId}` : nameKey;
    const info: InstitutionInfo = {
        name: name.trim(),
        rorId,
        rorUrl,
        connectedNodeIds: new Map([[connectedNodeId, edge]]),
    };
    map.set(key, info);
    if (rorId) {
        rorIndex.set(rorId, key);
    }
}

/**
 * Convert a PascalCase contributor type to a human-readable label.
 */
function humanizeContributorType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

/**
 * Collect institutions from a creator entry's affiliations.
 */
function collectCreatorAffiliations(
    creator: LandingPageCreator,
    personNodeId: string | null,
    map: Map<string, InstitutionInfo>,
    rorIndex: Map<string, string>,
): void {
    for (const affiliation of creator.affiliations ?? []) {
        if (!affiliation.name) continue;

        const targetNodeId = personNodeId ?? 'central';
        const isRor = affiliation.affiliation_identifier_scheme === 'ROR';

        mergeInstitution(
            map,
            rorIndex,
            affiliation.name,
            isRor ? affiliation.affiliation_identifier : null,
            targetNodeId,
            { edgeLabel: 'Affiliated with', relationType: 'AffiliatedWith' },
        );
    }
}

/**
 * Collect institutions from a contributor entry's affiliations.
 */
function collectContributorAffiliations(
    contributor: LandingPageContributor,
    personNodeId: string | null,
    map: Map<string, InstitutionInfo>,
    rorIndex: Map<string, string>,
): void {
    for (const affiliation of contributor.affiliations ?? []) {
        if (!affiliation.name) continue;

        const targetNodeId = personNodeId ?? 'central';
        const isRor = affiliation.affiliation_identifier_scheme === 'ROR';

        mergeInstitution(
            map,
            rorIndex,
            affiliation.name,
            isRor ? affiliation.affiliation_identifier : null,
            targetNodeId,
            { edgeLabel: 'Affiliated with', relationType: 'AffiliatedWith' },
        );
    }
}

/**
 * Hook that builds institution nodes and links for the Relation Browser graph.
 *
 * Collects institutions from:
 * 1. Direct institutional creators (creatorable.type === 'Institution')
 * 2. Direct institutional contributors (contributorable.type === 'Institution')
 * 3. Affiliations of person creators
 * 4. Affiliations of person contributors
 * 5. API response affiliations from related DOIs
 *
 * Institutions are deduplicated by ROR ID first, then by normalized name.
 */
export function useInstitutionNodes(
    resource: LandingPageResource,
    creatorNodeIdMap: Map<string, string>,
    contributorNodeIdMap: Map<string, string>,
    apiAuthorsWithAffiliations: Map<string, ApiAuthorWithAffiliations[]>,
): UseInstitutionNodesResult {
    return useMemo(() => {
        const institutionMap = new Map<string, InstitutionInfo>();
        const rorIndex = new Map<string, string>();

        // 1. Direct institutional creators → edge to central resource
        for (const creator of resource.creators ?? []) {
            if (creator.creatorable.type === 'Institution' && creator.creatorable.name) {
                const isRor = creator.creatorable.name_identifier_scheme === 'ROR';
                mergeInstitution(
                    institutionMap,
                    rorIndex,
                    creator.creatorable.name,
                    isRor ? creator.creatorable.name_identifier : null,
                    'central',
                    { edgeLabel: 'Created', relationType: 'Created' },
                );
            }
        }

        // 2. Direct institutional contributors → edge to central resource
        for (const contributor of resource.contributors ?? []) {
            if (contributor.contributorable.type === 'Institution' && contributor.contributorable.name) {
                const isRor = contributor.contributorable.name_identifier_scheme === 'ROR';
                const types = contributor.contributor_types.map(humanizeContributorType);
                const edgeLabel = types.length > 0 ? types.join(', ') : 'Contributor';
                const relationType = contributor.contributor_types[0] ?? 'Contributor';
                mergeInstitution(
                    institutionMap,
                    rorIndex,
                    contributor.contributorable.name,
                    isRor ? contributor.contributorable.name_identifier : null,
                    'central',
                    { edgeLabel, relationType },
                );
            }
        }

        // 3. Affiliations of person creators → edge to person node
        for (const creator of resource.creators ?? []) {
            if (creator.creatorable.type === 'Institution') continue;
            const personNodeId = findPersonNodeId(creator, creatorNodeIdMap);
            collectCreatorAffiliations(creator, personNodeId, institutionMap, rorIndex);
        }

        // 4. Affiliations of person contributors → edge to person node
        for (const contributor of resource.contributors ?? []) {
            if (contributor.contributorable.type === 'Institution') continue;
            const personNodeId = findContributorPersonNodeId(contributor, contributorNodeIdMap);
            collectContributorAffiliations(contributor, personNodeId, institutionMap, rorIndex);
        }

        // 5. API response affiliations from related DOIs → edge to remote creator nodes
        for (const [nodeId, authors] of apiAuthorsWithAffiliations) {
            for (const author of authors) {
                // 5b. Institutional API authors → edge to related DOI node
                if (author.type === 'Institution' && author.name) {
                    mergeInstitution(
                        institutionMap,
                        rorIndex,
                        author.name,
                        author.ror_id ?? null,
                        nodeId,
                        { edgeLabel: 'Created', relationType: 'Created' },
                    );
                    continue;
                }

                if (!author.affiliations) continue;
                for (const affiliation of author.affiliations) {
                    if (!affiliation.name) continue;
                    const isRor = affiliation.identifier_scheme === 'ROR';
                    // Find the creator node for this API author
                    const creatorKey = author.orcid
                        ? author.orcid
                        : `${(author.family_name ?? '').trim().toLowerCase()}|${(author.given_name ?? '').trim().toLowerCase()}`;
                    const targetNodeId = creatorNodeIdMap.get(creatorKey) ?? nodeId;
                    mergeInstitution(
                        institutionMap,
                        rorIndex,
                        affiliation.name,
                        isRor ? affiliation.identifier : null,
                        targetNodeId,
                        { edgeLabel: 'Affiliated with', relationType: 'AffiliatedWith' },
                    );
                }
            }
        }

        // Build graph nodes and links
        const institutionNodes: GraphNode[] = [];
        const institutionLinks: GraphLink[] = [];

        for (const [key, info] of institutionMap) {
            const nodeId = `institution-${key}`;

            institutionNodes.push({
                id: nodeId,
                label: truncateLabel(info.name),
                fullLabel: info.name,
                identifier: info.rorId ?? info.name,
                identifierType: 'Institution',
                relationType: 'AffiliatedWith',
                url: info.rorUrl,
                isCentral: false,
                nodeType: 'institution',
                orcid: null,
                rorId: info.rorId,
            });

            for (const [connectedId, edge] of info.connectedNodeIds) {
                // Affiliation edges point from person → institution;
                // Created/Contributor edges point from institution → central/DOI node
                const isAffiliation = edge.relationType === 'AffiliatedWith';
                institutionLinks.push({
                    source: isAffiliation ? connectedId : nodeId,
                    target: isAffiliation ? nodeId : connectedId,
                    relationType: edge.relationType,
                    relationLabel: edge.edgeLabel,
                });
            }
        }

        return { institutionNodes, institutionLinks };
    }, [resource.creators, resource.contributors, creatorNodeIdMap, contributorNodeIdMap, apiAuthorsWithAffiliations]);
}

/**
 * Find the graph node ID for a person creator using the creator node ID map.
 */
function findPersonNodeId(
    creator: LandingPageCreator,
    creatorNodeIdMap: Map<string, string>,
): string | null {
    const c = creator.creatorable;
    if (c.name_identifier_scheme === 'ORCID' && c.name_identifier) {
        const match = c.name_identifier.match(/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/);
        if (match) {
            const nodeId = creatorNodeIdMap.get(match[1]);
            if (nodeId) return nodeId;
        }
    }
    const nameKey = `${(c.family_name ?? '').trim().toLowerCase()}|${(c.given_name ?? '').trim().toLowerCase()}`;
    return creatorNodeIdMap.get(nameKey) ?? null;
}

/**
 * Find the graph node ID for a person contributor using the contributor node ID map.
 */
function findContributorPersonNodeId(
    contributor: LandingPageContributor,
    contributorNodeIdMap: Map<string, string>,
): string | null {
    const c = contributor.contributorable;
    if (c.name_identifier_scheme === 'ORCID' && c.name_identifier) {
        const match = c.name_identifier.match(/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/);
        if (match) {
            const nodeId = contributorNodeIdMap.get(match[1]);
            if (nodeId) return nodeId;
        }
    }
    const nameKey = `${(c.family_name ?? '').trim().toLowerCase()}|${(c.given_name ?? '').trim().toLowerCase()}`;
    return contributorNodeIdMap.get(nameKey) ?? null;
}

// Export helpers for testing
export {
    collectContributorAffiliations,
    collectCreatorAffiliations,
    findContributorPersonNodeId,
    findPersonNodeId,
    humanizeContributorType,
    mergeInstitution,
};
export type { InstitutionInfo };
