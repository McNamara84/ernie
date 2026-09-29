import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference';
import type { DataCiteFormProps } from '@/components/curation/types/datacite-form-types';
import type { SelectedKeyword } from '@/types/vocabulary';

export interface ImportedMetadata {
    doi?: string | null;
    year?: string | null;
    resourceType?: string | null;
    version?: string | null;
    language?: string | null;
    titles?: NonNullable<DataCiteFormProps['initialTitles']>;
    licenses?: string[];
    rawRights?: NonNullable<DataCiteFormProps['initialRawRights']>;
    authors?: NonNullable<DataCiteFormProps['initialAuthors']>;
    contributors?: NonNullable<DataCiteFormProps['initialContributors']>;
    descriptions?: NonNullable<DataCiteFormProps['initialDescriptions']>;
    dates?: NonNullable<DataCiteFormProps['initialDates']>;
    controlledKeywords?: SelectedKeyword[];
    gcmdKeywords?: SelectedKeyword[];
    mslKeywords?: SelectedKeyword[];
    gemetKeywords?: SelectedKeyword[];
    freeKeywords?: string[];
    coverages?: NonNullable<DataCiteFormProps['initialSpatialTemporalCoverages']>;
    relatedWorks?: NonNullable<DataCiteFormProps['initialRelatedWorks']>;
    relatedItems?: NonNullable<DataCiteFormProps['initialRelatedItems']>;
    fundingReferences?: Array<Omit<FundingReferenceEntry, 'id' | 'isExpanded'>>;
    mslLaboratories?: NonNullable<DataCiteFormProps['initialMslLaboratories']>;
    instruments?: NonNullable<DataCiteFormProps['initialInstruments']>;
}

export function identityPart(value: unknown): string {
    return String(value ?? '')
        .trim()
        .toLocaleLowerCase();
}

/** Preserve local IDs and filled values while adding details supplied by the file. */
export function fillMissing<T>(current: T, incoming: T): T {
    if (current === null || current === undefined || (typeof current === 'string' && current.trim() === '')) return incoming;
    if (Array.isArray(current) && Array.isArray(incoming)) {
        const result: unknown[] = [...current];
        const seen = new Set(result.map((item) => JSON.stringify(item)));
        for (const item of incoming) {
            const fingerprint = JSON.stringify(item);
            if (!seen.has(fingerprint)) {
                result.push(item);
                seen.add(fingerprint);
            }
        }
        return result as T;
    }
    if (typeof current === 'object' && typeof incoming === 'object' && !Array.isArray(current) && !Array.isArray(incoming)) {
        const result: Record<string, unknown> = { ...(current as Record<string, unknown>) };
        for (const [key, value] of Object.entries(incoming as Record<string, unknown>)) {
            if (key === 'id' || key === 'position' || key === 'isExpanded') continue;
            result[key] = fillMissing(result[key], value);
        }
        return result as T;
    }
    return current;
}

export function mergeImportedEntries<T>(
    current: T[],
    incoming: T[],
    identity: (entry: T) => string,
    isPlaceholder: (entry: T) => boolean = () => false,
): T[] {
    if (incoming.length === 0) return current;
    const result = current.filter((entry) => !isPlaceholder(entry));
    const positions = new Map(result.map((entry, index) => [identity(entry), index]));
    for (const entry of incoming) {
        if (isPlaceholder(entry)) continue;
        const key = identity(entry);
        const existingIndex = positions.get(key);
        if (existingIndex === undefined) {
            positions.set(key, result.length);
            result.push(entry);
        } else {
            result[existingIndex] = fillMissing(result[existingIndex], entry);
        }
    }
    return result;
}
