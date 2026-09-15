import type { LandingPageRelatedIdentifier, LandingPageRelatedItem } from '@/types/landing-page';

export interface ResourceRelatedWorkGroup {
    relatedIdentifiers: LandingPageRelatedIdentifier[];
    relatedItems: LandingPageRelatedItem[];
}

export interface PartitionedResourceRelatedWork {
    keyPublications: ResourceRelatedWorkGroup;
    datasetDescriptions: ResourceRelatedWorkGroup;
    remaining: ResourceRelatedWorkGroup;
}

type FeaturedRelation = 'keyPublications' | 'datasetDescriptions';

const FEATURED_RELATIONS: Record<string, FeaturedRelation> = {
    issupplementto: 'keyPublications',
    isdocumentedby: 'datasetDescriptions',
};

function normalizedRelationType(value: string | null | undefined): string {
    return (
        value
            ?.trim()
            .replace(/[^a-z0-9]/gi, '')
            .toLowerCase() ?? ''
    );
}

function featuredRelation(...candidates: Array<string | null | undefined>): FeaturedRelation | null {
    for (const candidate of candidates) {
        const group = FEATURED_RELATIONS[normalizedRelationType(candidate)];

        if (group) {
            return group;
        }
    }

    return null;
}

function emptyGroup(): ResourceRelatedWorkGroup {
    return { relatedIdentifiers: [], relatedItems: [] };
}

/**
 * Split regular Resource landing-page relations into dedicated cards and the
 * remaining Related Work payload without changing source order.
 */
export function partitionResourceRelatedWork(
    relatedIdentifiers: LandingPageRelatedIdentifier[],
    relatedItems: LandingPageRelatedItem[],
): PartitionedResourceRelatedWork {
    const partitioned: PartitionedResourceRelatedWork = {
        keyPublications: emptyGroup(),
        datasetDescriptions: emptyGroup(),
        remaining: emptyGroup(),
    };

    for (const relatedIdentifier of relatedIdentifiers) {
        const group = featuredRelation(relatedIdentifier.relation_type);
        (group ? partitioned[group] : partitioned.remaining).relatedIdentifiers.push(relatedIdentifier);
    }

    for (const relatedItem of relatedItems) {
        const group = featuredRelation(relatedItem.relation_type_slug, relatedItem.relation_type);
        (group ? partitioned[group] : partitioned.remaining).relatedItems.push(relatedItem);
    }

    return partitioned;
}
