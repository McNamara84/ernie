/**
 * Related Work Zod Schemas
 *
 * Validation schemas for related identifiers/works in the DataCite form.
 */

import { z } from 'zod';

// =============================================================================
// Identifier Types (DataCite 4.6)
// =============================================================================

export const identifierTypes = [
    'DOI',
    'URL',
    'Handle',
    'IGSN',
    'URN',
    'ISBN',
    'ISSN',
    'PURL',
    'ARK',
    'arXiv',
    'bibcode',
    'CSTR',
    'EAN13',
    'EISSN',
    'ISTC',
    'LISSN',
    'LSID',
    'PMID',
    'RRID',
    'UPC',
    'w3id',
] as const;

export const identifierTypeSchema = z.enum(identifierTypes);

export type IdentifierType = z.infer<typeof identifierTypeSchema>;

// =============================================================================
// Relation Types (DataCite 4.6)
// =============================================================================

export const relationTypes = [
    // Citation
    'Cites',
    'IsCitedBy',
    'References',
    'IsReferencedBy',
    // Documentation
    'Documents',
    'IsDocumentedBy',
    'Describes',
    'IsDescribedBy',
    // Versions
    'IsNewVersionOf',
    'IsPreviousVersionOf',
    'HasVersion',
    'IsVersionOf',
    'Continues',
    'IsContinuedBy',
    'Obsoletes',
    'IsObsoletedBy',
    'IsVariantFormOf',
    'IsOriginalFormOf',
    'IsIdenticalTo',
    // Compilation
    'HasPart',
    'IsPartOf',
    'Compiles',
    'IsCompiledBy',
    // Derivation
    'IsSourceOf',
    'IsDerivedFrom',
    // Supplement
    'IsSupplementTo',
    'IsSupplementedBy',
    // Software
    'Requires',
    'IsRequiredBy',
    // Metadata
    'HasMetadata',
    'IsMetadataFor',
    // Reviews
    'Reviews',
    'IsReviewedBy',
    // Other
    'IsPublishedIn',
    'Collects',
    'IsCollectedBy',
] as const;

export const relationTypeSchema = z.enum(relationTypes);

export type RelationType = z.infer<typeof relationTypeSchema>;

// =============================================================================
// Related Identifier Schema
// =============================================================================

export const relatedIdentifierSchema = z.object({
    id: z.number().optional(),
    identifier: z.string().min(1, 'Identifier is required'),
    identifier_type: identifierTypeSchema,
    relation_type: relationTypeSchema,
    position: z.number().optional(),
    related_title: z.string().optional().nullable(),
    related_metadata: z.record(z.string(), z.unknown()).optional().nullable(),
});

export type RelatedIdentifierFormData = z.infer<typeof relatedIdentifierSchema>;

// =============================================================================
// Related Identifiers Array Schema
// =============================================================================

export const relatedIdentifiersArraySchema = z.array(relatedIdentifierSchema).default([]);

export type RelatedIdentifiersArrayFormData = z.infer<typeof relatedIdentifiersArraySchema>;

// =============================================================================
// Form-friendly Related Work Schema (matches RelatedIdentifierFormData type)
// =============================================================================

export const relatedWorkFormSchema = z.object({
    identifier: z.string().min(1, 'Identifier is required'),
    identifierType: identifierTypeSchema,
    relationType: relationTypeSchema,
});

export type RelatedWorkFormData = z.infer<typeof relatedWorkFormSchema>;
