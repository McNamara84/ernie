import type { LandingPageAffiliation, LandingPageContributor, LandingPageCreator, LandingPageCreatorable } from '@/types/landing-page';

export interface LandingPageDisplayCreator extends LandingPageCreator {
    contributor_types: string[];
}

export type LandingPageDisplayContributor = LandingPageContributor;

export interface LandingPageDisplayCredits {
    creators: LandingPageDisplayCreator[];
    contributors: LandingPageDisplayContributor[];
}

function entityKey(entity: LandingPageCreatorable): string | null {
    const type = entity.type.trim().toLowerCase();
    const id = entity.id;

    if (type === '' || typeof id !== 'number' || !Number.isSafeInteger(id) || id <= 0) {
        return null;
    }

    return `${type}:${id}`;
}

function normalizeRole(role: string): string {
    return role.trim().replace(/\s+/g, ' ');
}

function mergeRoles(...roleGroups: string[][]): string[] {
    const roles: string[] = [];
    const seen = new Set<string>();

    roleGroups.flat().forEach((role) => {
        const normalized = normalizeRole(role);
        const key = normalized.toLowerCase();

        if (normalized === '' || seen.has(key)) {
            return;
        }

        seen.add(key);
        roles.push(normalized);
    });

    return roles;
}

function normalizedAffiliationIdentifier(affiliation: LandingPageAffiliation): string | null {
    const scheme = affiliation.affiliation_identifier_scheme?.trim().toLowerCase() ?? '';
    let identifier = affiliation.affiliation_identifier
        ?.trim()
        .replace(/^https?:\/\//i, '')
        .replace(/^www\./i, '')
        .replace(/\/+$/g, '')
        .toLowerCase();

    if (scheme === 'ror') {
        identifier = identifier?.replace(/^ror\.org\//, '');
    }

    if (scheme === '' || !identifier) {
        return null;
    }

    return `${scheme}:${identifier}`;
}

function normalizedAffiliationName(affiliation: LandingPageAffiliation): string | null {
    const name = affiliation.name.trim().replace(/\s+/g, ' ').toLowerCase();

    return name === '' ? null : name;
}

function mergeAffiliationMetadata(existing: LandingPageAffiliation, incoming: LandingPageAffiliation): LandingPageAffiliation {
    const shouldAdoptName = existing.name.trim() === '' && incoming.name.trim() !== '';
    const shouldAdoptIdentifier = normalizedAffiliationIdentifier(existing) === null && normalizedAffiliationIdentifier(incoming) !== null;

    return {
        ...existing,
        name: shouldAdoptName ? incoming.name : existing.name,
        affiliation_identifier: shouldAdoptIdentifier ? incoming.affiliation_identifier : existing.affiliation_identifier,
        affiliation_identifier_scheme: shouldAdoptIdentifier ? incoming.affiliation_identifier_scheme : existing.affiliation_identifier_scheme,
    };
}

function mergeAffiliations(...affiliationGroups: LandingPageAffiliation[][]): LandingPageAffiliation[] {
    const affiliations: LandingPageAffiliation[] = [];
    const identifierIndexes = new Map<string, number>();
    const nameIndexes = new Map<string, number>();

    affiliationGroups.flat().forEach((affiliation) => {
        const identifierKey = normalizedAffiliationIdentifier(affiliation);
        const nameKey = normalizedAffiliationName(affiliation);
        let existingIndex = identifierKey === null ? undefined : identifierIndexes.get(identifierKey);

        if (existingIndex === undefined && nameKey !== null) {
            const sameNameIndex = nameIndexes.get(nameKey);

            if (sameNameIndex !== undefined) {
                const existingIdentifierKey = normalizedAffiliationIdentifier(affiliations[sameNameIndex]);

                if (identifierKey === null || existingIdentifierKey === null) {
                    existingIndex = sameNameIndex;
                }
            }
        }

        if (existingIndex !== undefined) {
            affiliations[existingIndex] = mergeAffiliationMetadata(affiliations[existingIndex], affiliation);

            const mergedIdentifierKey = normalizedAffiliationIdentifier(affiliations[existingIndex]);
            if (mergedIdentifierKey !== null) {
                identifierIndexes.set(mergedIdentifierKey, existingIndex);
            }

            const mergedNameKey = normalizedAffiliationName(affiliations[existingIndex]);
            if (mergedNameKey !== null) {
                nameIndexes.set(mergedNameKey, existingIndex);
            }

            return;
        }

        const nextIndex = affiliations.length;
        affiliations.push({ ...affiliation });

        if (identifierKey !== null) {
            identifierIndexes.set(identifierKey, nextIndex);
        }
        if (nameKey !== null) {
            nameIndexes.set(nameKey, nextIndex);
        }
    });

    return affiliations;
}

function cloneCreator(creator: LandingPageCreator): LandingPageDisplayCreator {
    return {
        ...creator,
        creatorable: { ...creator.creatorable },
        affiliations: mergeAffiliations(creator.affiliations),
        contributor_types: [],
    };
}

function cloneContributor(contributor: LandingPageContributor): LandingPageDisplayContributor {
    return {
        ...contributor,
        contributorable: { ...contributor.contributorable },
        affiliations: mergeAffiliations(contributor.affiliations),
        contributor_types: mergeRoles(contributor.contributor_types),
    };
}

/**
 * Builds the human-facing Creator and Contributor lists without changing the
 * DataCite-shaped resource payload used by citations, exports, and IGSN logic.
 */
export function mergeLandingPageCredits(creators: LandingPageCreator[], contributors: LandingPageContributor[]): LandingPageDisplayCredits {
    const displayCreators: LandingPageDisplayCreator[] = [];
    const creatorIndexes = new Map<string, number>();

    creators.forEach((creator, index) => {
        const key = entityKey(creator.creatorable) ?? `creator-row:${index}`;
        const existingIndex = creatorIndexes.get(key);

        if (existingIndex === undefined) {
            creatorIndexes.set(key, displayCreators.length);
            displayCreators.push(cloneCreator(creator));
            return;
        }

        const existing = displayCreators[existingIndex];
        existing.affiliations = mergeAffiliations(existing.affiliations, creator.affiliations);
    });

    const displayContributors: LandingPageDisplayContributor[] = [];
    const contributorIndexes = new Map<string, number>();

    contributors.forEach((contributor, index) => {
        const stableEntityKey = entityKey(contributor.contributorable);
        const key = stableEntityKey ?? `contributor-row:${index}`;
        const creatorIndex = stableEntityKey === null ? undefined : creatorIndexes.get(stableEntityKey);

        if (creatorIndex !== undefined) {
            const creator = displayCreators[creatorIndex];
            creator.contributor_types = mergeRoles(creator.contributor_types, contributor.contributor_types);
            creator.affiliations = mergeAffiliations(creator.affiliations, contributor.affiliations);
            return;
        }

        const existingIndex = contributorIndexes.get(key);
        if (existingIndex === undefined) {
            contributorIndexes.set(key, displayContributors.length);
            displayContributors.push(cloneContributor(contributor));
            return;
        }

        const existing = displayContributors[existingIndex];
        existing.contributor_types = mergeRoles(existing.contributor_types, contributor.contributor_types);
        existing.affiliations = mergeAffiliations(existing.affiliations, contributor.affiliations);
    });

    return {
        creators: displayCreators,
        contributors: displayContributors,
    };
}
