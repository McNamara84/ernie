import { z } from 'zod';

const nameTypeSchema = z.enum(['Personal', 'Organizational']);

// Match the backend rule (`StoreRelatedItemRequest`): allow up to five years
// in the future to accommodate forthcoming publications, so users do not see
// confusing 422 errors after the form passes client-side validation.
const maxPublicationYear = (): number => new Date().getFullYear() + 5;

const affiliationSchema = z.object({
    name: z.string().min(1, 'Affiliation name is required'),
    affiliation_identifier: z.string().nullish(),
    scheme: z.string().nullish(),
    // Mirror the backend rule: `nullable, string, max:512`, no URL constraint.
    scheme_uri: z.string().max(512).nullish(),
});

const creatorSchema = z.object({
    id: z.number().optional(),
    name: z.string().min(1, 'Name is required'),
    name_type: nameTypeSchema,
    given_name: z.string().nullish(),
    family_name: z.string().nullish(),
    name_identifier: z.string().nullish(),
    name_identifier_scheme: z.string().nullish(),
    // Match the backend rule (`StoreRelatedItemRequest`): `nullable, string,
    // max:512` with NO URL constraint. DataCite accepts non-URL scheme URIs
    // (e.g. legacy registry shortcuts), so a strict client-side `.url()`
    // would reject server-accepted payloads.
    scheme_uri: z.string().max(512).nullish(),
    position: z.number().int().nonnegative(),
    affiliations: z.array(affiliationSchema).default([]),
});

const contributorSchema = creatorSchema.extend({
    contributor_type: z.string().min(1, 'Contributor type is required'),
});

const titleSchema = z.object({
    id: z.number().optional(),
    title: z.string().min(1, 'Title is required'),
    title_type: z.enum(['MainTitle', 'Subtitle', 'TranslatedTitle', 'AlternativeTitle']),
    position: z.number().int().nonnegative(),
});

export const relatedItemSchema = z
    .object({
        id: z.number().optional(),
        related_item_type: z.string().min(1, 'Related item type is required'),
        relation_type_id: z.number().int().positive('Relation type is required'),
        publication_year: z
            .number()
            .int()
            .min(1000, 'Year must be ≥ 1000')
            .max(maxPublicationYear(), `Year must be ≤ ${maxPublicationYear()}`)
            .nullish(),
        volume: z.string().max(50).nullish(),
        issue: z.string().max(50).nullish(),
        number: z.string().max(50).nullish(),
        number_type: z.enum(['Article', 'Chapter', 'Report', 'Other']).nullish(),
        first_page: z.string().max(50).nullish(),
        last_page: z.string().max(50).nullish(),
        publisher: z.string().max(255).nullish(),
        edition: z.string().max(100).nullish(),
        // Backend (`StoreRelatedItemRequest` + `related_items` migration)
        // accepts up to 2183 characters; keep the client rule in sync to avoid
        // blocking valid URL identifiers.
        identifier: z.string().max(2183).nullish(),
        identifier_type: z.string().max(50).nullish(),
        related_metadata_scheme: z.string().max(255).nullish(),
        scheme_uri: z.string().max(512).nullish(),
        scheme_type: z.string().max(64).nullish(),
        position: z.number().int().nonnegative(),
        titles: z.array(titleSchema).min(1, 'At least one title is required'),
        creators: z.array(creatorSchema).default([]),
        contributors: z.array(contributorSchema).default([]),
    })
    .refine(
        (val) => val.titles.some((t) => t.title_type === 'MainTitle' && t.title.trim() !== ''),
        { message: 'A non-empty MainTitle is required', path: ['titles'] },
    );

export type RelatedItemInput = z.infer<typeof relatedItemSchema>;
