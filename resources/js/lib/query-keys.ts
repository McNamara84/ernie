import { validate as doiValidate } from '@/routes/api/doi';

/**
 * Centralised QueryKey factory for TanStack Query.
 *
 * Whenever possible, the backend route is derived from Wayfinder so that a
 * URL change automatically invalidates the matching cache entry.
 *
 * For routes not (yet) exposed via Wayfinder (e.g. `/api/v1/ror-affiliations`,
 * `/vocabularies/*`), a stable string literal is used.
 */
export const queryKeys = {
    ror: {
        all: () => ['ror', 'affiliations'] as const,
        resolve: (batch: readonly string[]) => ['ror', 'resolve', [...batch].sort()] as const,
    },
    doi: {
        validate: (doi: string, excludeResourceId?: number) =>
            [doiValidate.url(), doi, excludeResourceId ?? null] as const,
    },
    pid4inst: {
        instruments: () => ['pid4inst', 'instruments'] as const,
    },
    msl: {
        vocabularyUrl: () => ['msl', 'vocabulary-url'] as const,
        laboratories: () => ['msl', 'laboratories'] as const,
    },
} as const;

/**
 * Endpoint URLs used by the migrated hooks. Keeping them in a single module
 * simplifies testing (MSW handlers) and avoids magic strings scattered across
 * hook implementations.
 */
export const apiEndpoints = {
    rorAffiliations: '/api/v1/ror-affiliations',
    rorResolve: '/api/v1/ror-resolve',
    doiValidate: doiValidate.url(),
    pid4instInstruments: '/vocabularies/pid4inst-instruments',
    mslVocabularyUrl: '/vocabularies/msl-vocabulary-url',
} as const;
