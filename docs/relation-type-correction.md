# Relation Type Correction Assistant

This assistant implements [#768](https://github.com/McNamara84/ernie/issues/768) and its rule, discovery and review tasks [#790](https://github.com/McNamara84/ernie/issues/790), [#791](https://github.com/McNamara84/ernie/issues/791) and [#792](https://github.com/McNamara84/ernie/issues/792). Admins and Group Leaders review existing related identifiers in Assistance. Acceptance changes only the selected row's `relation_type_id`, updates its resource's modification attribution, records a durable audit and invokes the usual DataCite synchronization workflow. No reciprocal record changes automatically.

## Rules and supported vocabulary

The inventory contains all 39 [DataCite 4.7 relation types](https://datacite-metadata-schema.readthedocs.io/en/4.7/appendices/appendix-1/relationType/). A supported vocabulary entry does not imply an automatic correction rule. Only explicit primary assertions for the exact directed DOI pair can produce a proposal. Resource types, identifier formats, title similarity and citation labels alone never establish a correction.

| Family | Types | Correction policy |
| --- | --- | --- |
| Citation | Cites, IsCitedBy, References, IsReferencedBy | Preserve possible mutual citations. Bibliographic references are supporting evidence only. |
| Supplement | IsSupplementTo, IsSupplementedBy | Additional citations do not disprove a supplement relation. No direction replacement without exclusive role evidence. |
| Chronological versions | IsNewVersionOf, IsPreviousVersionOf | Exact primary assertion can correct the reversed role. Never infer sequence from dates or version strings. |
| Concept/version | HasVersion, IsVersionOf | Exact primary assertion can correct the reversed role. Keep separate from chronological versions. |
| Whole/part | HasPart, IsPartOf | Exact primary assertion can correct the reversed structural role. |
| Derivation | IsSourceOf, IsDerivedFrom | Exact primary assertion can correct the reversed structural role. Crossref `is-based-on` is insufficient. |
| Continuation | Continues, IsContinuedBy | Exact primary assertion can correct the reversed structural role. |
| Obsolescence | Obsoletes, IsObsoletedBy | Exact DataCite assertion can correct the reversed role. Crossmark corrections and retractions are not equivalent. |
| Original/variant | IsOriginalFormOf, IsVariantFormOf | Exact primary assertion can correct the reversed structural role. |
| Compilation | Compiles, IsCompiledBy | Exact primary assertion can correct the reversed structural role. Software resource type alone is insufficient. |
| Collection | Collects, IsCollectedBy | Exact primary assertion can correct the reversed structural role. Instrument resource type alone is insufficient. |
| Documentation/description | Documents, IsDocumentedBy, Describes, IsDescribedBy | Recognize and display exact assertions; preserve possible multiple roles. |
| Metadata | HasMetadata, IsMetadataFor | Recognize assertions and preserve schema annotations; no direction correction without exclusive role evidence. |
| Review | Reviews, IsReviewedBy | Recognize Crossref `is-review-of`/`has-review`; review-like titles are insufficient. |
| Dependency | Requires, IsRequiredBy | Preserve possible cyclic dependencies. |
| Translation | HasTranslation, IsTranslationOf | Recognize assertions; simultaneous multilingual editions need original/translation context before direction correction. |
| Identity | IsIdenticalTo | Symmetric; no invented direction replacement. |
| Publication container | IsPublishedIn | No DataCite inverse; do not approximate as IsPartOf. |
| Other | Other | Specialize only an unqualified Other with one unambiguous explicit primary type. Never propose Other or overwrite custom relation information. |

The `reversed-structural-role` rule requires an explicit primary assertion of the opposite type from the resource's perspective. A resource's own published current type is comparison context and does not independently confirm the local value. Competing primary roles from the related work, or multiple proposed types, suppress the proposal. The `explicit-relation` rule specializes an unqualified Other. All proposals have high categorical confidence: score 0.90 for one origin, 0.95 for assertions from both identifier owners. These scores are policy levels, not calibrated probabilities. Copies from another provider do not raise the score.

Current rules use exact DOI pairs. URLs, non-DOI identifiers, self relations, additional unrelated relation types, uncertain cyclic roles and arbitrary free text are outside automatic correction. Local reverse rows alone are not independent evidence of which direction is wrong. Both local resources are scanned independently against primary assertions; neither requires nor triggers acceptance on the other.

The [DataCite versioning guidance](https://support.datacite.org/docs/versioning) distinguishes version sequences from concept/version roles. The [RelatedIdentifier specification](https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/) restricts schema annotations to HasMetadata/IsMetadataFor. An incompatible proposal is suppressed rather than removing annotations. Citation labels, identifiers, row order, source, resource type general and qualifying information are preserved.

## Sources and failure handling

DataCite `relatedIdentifiers` and Crossref raw `relation` records for both DOIs are primary sources. Crossref's [relationship vocabulary](https://www.crossref.org/documentation/schema-library/markup-guide-metadata-segments/relationships/) and [JSON format](https://github.com/CrossRef/rest-api-doc/blob/master/api_format.md) determine exact mappings and `asserted-by` provenance. Generated inverse relationships retain the original claimant; the record queried is not necessarily the claimant. Expressions, manifestations, preprints and generic based-on relationships have no approximate mapping. Crossref `reference` entries are supporting citation assertions.

ScholExplorer and DataCite Event Data supplement the displayed evidence, never justify corrections alone or increase confidence. Event Data uses `datacite-related`, `datacite-crossref` and `crossref`. Its [changes to supported events](https://support.datacite.org/docs/datacite-event-data-changes) make absent events unsuitable as negative evidence. Incomplete supplementary pagination is explicitly recorded, bounded to three pages per provider, and never followed through arbitrary next-page URLs. Source direction is retained; the existing Event Data relation-discovery adapter also now inverts relations when the queried DOI is the object and skips types without an inverse.

Primary caches last 24 hours for successful or missing records, five minutes for unavailable/incomplete responses. Only transient failures (429, 5xx, connection failures) retry once. Invalid record identifiers or malformed relation collections are incomplete, not empty evidence. Primary failures retain pending proposals and prevent review actions until evidence can be checked. Supplementary failures do not veto primary evidence.

Scans load identifier/resource/type context in chunks of 100, reuse per-DOI source caches and skip API reads when no rule applies. Pending projections exclude deleted or reassigned targets. Completed scans remove stale proposals and invalidate counts even when no new proposals were created. Discovery rechecks the pre-request row and resource snapshot under locks before writing, so late HTTP results cannot reintroduce a consumed or edited proposal.

## Review, dismissal and audit

`metadata` carries the contract and policy versions, current row/resource snapshot, proposed type, rule, confidence, directed evidence with claimant, original relation, mapping version, source URL/pointer and retrieval time, plus source completeness and fingerprints.

The **context fingerprint** includes material row/resource context, proposed type and canonical primary assertions. It excludes citation display text, position, retrieval times, API ordering, duplicate copies and supplementary availability. Therefore an unchanged dismissed case stays suppressed; a changed primary assertion or resource/identifier context can resurface. Policy version alone does not resurface a dismissal. `suggested_value` combines proposed slug and context fingerprint to use the existing generic dismissal uniqueness rules.

The **review fingerprint** also covers the displayed local snapshot, proposed type and contract/policy versions. Both single and resource batch actions submit it as `relation_type_correction_fingerprint`. Expired primary evidence is refreshed before acquiring locks. Resource, related rows (ordered by ID), suggestion and proposed type are rechecked transactionally. Acceptance rejects stale previews, inactive types, client overrides and equivalent replacement duplicates, including DOI URL/case variants. Audit failure rolls back the entire local operation. Batch synchronization runs once per affected resource after the actions; remote sync failure retains accepted metadata and its audit and exposes the existing retry action.

`relation_type_correction_reviews` records acceptance and decline with the server-authenticated actor, time, optional reason, old/proposed type snapshots and evidence. Nullable references use `ON DELETE SET NULL`; snapshots retain original identifiers, resource/row/type/actor IDs after deletion. The suggestion ID is unique without a foreign key to the consumed proposal. There is no history UI in this release.

## Deployment and verification

Run migrations and the normal deployment cache rebuild. In installations with cached module manifests or routes, clear optimization caches before rebuilding them (`php artisan optimize:clear`); the new manifest must be included in the rebuilt assistant registry. Restart existing queue workers as usual. No new environment variables or background schedule are required.

Focused PHP tests: `npm run test:php -- tests/pest/Unit/Services/RelationTypeCorrection tests/pest/Feature/Services/RelationTypeCorrection`. MySQL 9.7 verification: `npm run test:php:mysql-sensitive:relation-correction` (isolated `ernie_test` schema). Browser verification after building frontend assets: `npm run test:php -- tests/pest/Browser/RelationTypeCorrectionAssistanceTest.php`. Frontend review tests: `npm run test:run -- tests/vitest/pages/__tests__/assistance-relation-correction.test.tsx`. Final checks use `npm run check:backend` and `npm run check:frontend`. Changed PHP and module code participates in CI coverage.
