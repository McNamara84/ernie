import { useMemo } from 'react';

import type { LandingPageContributor, LandingPageResource } from '@/types/landing-page';

import type { GraphLink, GraphNode } from './graph-types';

interface ContributorInfo {
    givenName: string | null;
    familyName: string | null;
    institutionName: string | null;
    orcid: string | null;
    contributorTypes: string[];
    datasetNodeIds: Set<string>;
}

interface UseContributorNodesResult {
    contributorNodes: GraphNode[];
    contributorLinks: GraphLink[];
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
 * Build a display label from contributor name parts: "FamilyName, GivenName".
 */
function buildContributorLabel(info: ContributorInfo): string {
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
 * Build a stable node ID for a contributor.
 */
function buildContributorId(info: ContributorInfo): string {
    if (info.orcid) {
        return `contributor-${info.orcid}`;
    }
    const nameKey = normalizeNameKey(info.familyName, info.givenName);
    if (nameKey !== '|') {
        return `contributor-${nameKey}`;
    }
    if (info.institutionName) {
        return `contributor-${info.institutionName.trim().toLowerCase()}`;
    }
    return `contributor-unknown-${Math.random().toString(36).slice(2, 8)}`;
}

/**
 * Build ORCID profile URL from an ORCID identifier.
 */
function buildOrcidUrl(orcid: string | null): string | null {
    if (!orcid) return null;
    return `https://orcid.org/${orcid}`;
}

/**
 * Convert a PascalCase contributor type to a human-readable label.
 * e.g. "DataCollector" → "Data Collector", "HostingInstitution" → "Hosting Institution"
 */
function humanizeContributorType(type: string): string {
    return type.replace(/([A-Z])/g, ' $1').trim();
}

/**
 * Merge a contributor into the deduplication map.
 * Deduplicates by ORCID first, then by normalized name.
 * Merges contributor types when the same person appears multiple times.
 */
function mergeContributor(
    map: Map<string, ContributorInfo>,
    orcidIndex: Map<string, string>,
    contributor: {
        givenName: string | null;
        familyName: string | null;
        institutionName: string | null;
        orcid: string | null;
        contributorTypes: string[];
    },
    datasetNodeId: string,
): void {
    // Try to find existing entry by ORCID
    if (contributor.orcid) {
        const existingKey = orcidIndex.get(contributor.orcid);
        if (existingKey) {
            const existing = map.get(existingKey)!;
            existing.datasetNodeIds.add(datasetNodeId);
            for (const ct of contributor.contributorTypes) {
                if (!existing.contributorTypes.includes(ct)) {
                    existing.contributorTypes.push(ct);
                }
            }
            return;
        }
    }

    // Try to find existing entry by normalized name
    const nameKey = normalizeNameKey(contributor.familyName, contributor.givenName);
    if (nameKey !== '|') {
        const existingByName = map.get(nameKey);
        if (existingByName) {
            existingByName.datasetNodeIds.add(datasetNodeId);
            for (const ct of contributor.contributorTypes) {
                if (!existingByName.contributorTypes.includes(ct)) {
                    existingByName.contributorTypes.push(ct);
                }
            }
            if (contributor.orcid && !existingByName.orcid) {
                existingByName.orcid = contributor.orcid;
                orcidIndex.set(contributor.orcid, nameKey);
            }
            return;
        }
    }

    // New contributor
    const info: ContributorInfo = {
        givenName: contributor.givenName,
        familyName: contributor.familyName,
        institutionName: contributor.institutionName,
        orcid: contributor.orcid,
        contributorTypes: [...contributor.contributorTypes],
        datasetNodeIds: new Set([datasetNodeId]),
    };

    const key = nameKey !== '|' ? nameKey : `inst-${(contributor.institutionName ?? 'unknown').trim().toLowerCase()}`;
    map.set(key, info);
    if (contributor.orcid) {
        orcidIndex.set(contributor.orcid, key);
    }
}

/**
 * Extract contributor info from a LandingPageContributor.
 */
function fromLandingPageContributor(contributor: LandingPageContributor): {
    givenName: string | null;
    familyName: string | null;
    institutionName: string | null;
    orcid: string | null;
    contributorTypes: string[];
} {
    const c = contributor.contributorable;
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
        contributorTypes: contributor.contributor_types,
    };
}

/**
 * Hook that builds contributor nodes and links for the Relation Browser graph.
 *
 * Processes contributors from the central resource (Inertia props).
 * Contributors are deduplicated by ORCID first, then by normalized name.
 * Each contributor gets edges labeled with their specific contributor types
 * (e.g. "Data Collector", "Editor").
 */
export function useContributorNodes(resource: LandingPageResource): UseContributorNodesResult {
    return useMemo(() => {
        const contributorMap = new Map<string, ContributorInfo>();
        const orcidIndex = new Map<string, string>();

        const contributors = resource.contributors ?? [];
        for (const contributor of contributors) {
            mergeContributor(
                contributorMap,
                orcidIndex,
                fromLandingPageContributor(contributor),
                'central',
            );
        }

        const contributorNodes: GraphNode[] = [];
        const contributorLinks: GraphLink[] = [];

        for (const info of contributorMap.values()) {
            const nodeId = buildContributorId(info);
            const label = buildContributorLabel(info);
            const orcidUrl = buildOrcidUrl(info.orcid);
            const primaryType = info.contributorTypes[0] ?? 'Other';
            const humanizedTypes = info.contributorTypes.map(humanizeContributorType);

            contributorNodes.push({
                id: nodeId,
                label,
                fullLabel: label,
                identifier: info.orcid ?? label,
                identifierType: 'Contributor',
                relationType: primaryType,
                url: orcidUrl,
                isCentral: false,
                nodeType: 'contributor',
                orcid: info.orcid,
                contributorTypes: humanizedTypes,
            });

            for (const datasetNodeId of info.datasetNodeIds) {
                contributorLinks.push({
                    source: nodeId,
                    target: datasetNodeId,
                    relationType: primaryType,
                    relationLabel: humanizedTypes.join(', '),
                });
            }
        }

        return { contributorNodes, contributorLinks };
    }, [resource.contributors]);
}

// Export helpers for testing
export {
    normalizeNameKey,
    buildContributorLabel,
    buildContributorId,
    humanizeContributorType,
    mergeContributor,
    fromLandingPageContributor,
};
export type { ContributorInfo };
