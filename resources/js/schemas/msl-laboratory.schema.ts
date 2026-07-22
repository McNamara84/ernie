import { z } from 'zod';

const canonicalRorUrlSchema = z.string().regex(/^https:\/\/ror\.org\/[0-9a-z]{9}$/i, 'Expected a canonical ROR URL');

export const mslLaboratorySchema = z.object({
    identifier: z.string().min(1),
    name: z.string().min(1),
    affiliation_name: z.string(),
    affiliation_ror: z.string().nullable(),
});

export type MslLaboratoryFormData = z.infer<typeof mslLaboratorySchema>;

export const mslLaboratoriesArraySchema = z.array(mslLaboratorySchema).default([]);

export const mslLaboratoryVocabularyEntrySchema = mslLaboratorySchema.extend({
    display_name: z.string().min(1),
    affiliation_name: z.string().min(1),
    affiliation_ror: canonicalRorUrlSchema.nullable(),
    scientific_domain: z.string().min(1),
    country: z.string().min(1),
});

export const mslLaboratoriesResponseSchema = z
    .object({
        version: z.string().min(1),
        lastUpdated: z.string().min(1),
        total: z.number().int().nonnegative(),
        data: z.array(mslLaboratoryVocabularyEntrySchema),
    })
    .refine((payload) => payload.total === payload.data.length, {
        message: 'Laboratory total does not match the number of entries',
        path: ['total'],
    });
