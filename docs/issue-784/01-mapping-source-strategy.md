# Issue 784: Crossref Funder ID to ROR Mapping Source Strategy

This document covers only the source strategy for mapping existing DataCite funding reference identifiers from `Crossref Funder ID` to `ROR`.

It intentionally does not cover the other sub-issues under the parent epic, does not define a generic organization resolution platform, and does not replace the existing broad ROR name-matching assistant for affiliations, institutions, or funders.

## Local Context

ERNIE stores funding references in `funding_references` with these relevant fields:

- `funder_name`
- `funder_identifier`
- `funder_identifier_type_id`
- `scheme_uri`
- award fields, which must be preserved

The accepted local DataCite funder identifier types are seeded in `funder_identifier_types`:

- `Crossref Funder ID`
- `GRID`
- `ISNI`
- `ROR`
- `Other`

DataCite export reads the current `FundingReference` through `DataCiteFundingReferenceMappingService`, so a successful Crossref-to-ROR replacement must update all three identifier fields together:

- `funder_identifier`
- `funder_identifier_type_id`
- `scheme_uri`

## Source Facts

The source strategy is based on these official references:

- ROR transition guidance: https://ror.readme.io/docs/funder-registry
- ROR mapping guidance: https://ror.readme.io/docs/mapping
- ROR data structure, current stable schema: https://ror.readme.io/docs/ror-data-structure
- ROR data dump: https://ror.readme.io/docs/data-dump
- ROR Zenodo download guidance: https://ror.readme.io/docs/zenodo
- Crossref Open Funder Registry access: https://www.crossref.org/documentation/funder-registry/accessing-the-funder-registry/
- Crossref funder API guidance: https://www.crossref.org/documentation/funder-registry/funder-data-via-the-api/
- DataCite FundingReference 4.7: https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/fundingreference/

The important implications are:

- ROR is the target registry for funder identification going forward.
- ROR schema 2.1 is the current recommended stable schema.
- ROR records can include Crossref Open Funder Registry identifiers in `external_ids` with type `fundref`.
- ROR `external_ids` include both `all` and `preferred`; matching must not be limited to `preferred`.
- ROR data dump JSON is the format of record. CSV is convenient but flattened.
- ROR releases are typically issued at least monthly. The REST API is updated with releases, and Zenodo hosts versioned data dumps.
- Crossref Funder IDs are DOIs with prefix `10.13039`; Crossref API queries use the DOI suffix.
- DataCite allows `Crossref Funder ID` and `ROR` as controlled `funderIdentifierType` values and allows `schemeURI` for the identifier scheme.

## Recommended Source Order

### 1. ROR data dump JSON as the primary source

Use the latest ROR data dump JSON from Zenodo as the primary source for batch mapping.

Reasons:

- It is versioned and can be cited in provenance.
- It avoids API rate limits and transient network failures during discovery.
- It gives full record context, including `status`, `types`, `names`, `relationships`, `admin.last_modified`, and `external_ids`.
- It supports deterministic tests by pinning a fixture or release snapshot.

Required processing:

- Use schema 2.1 records where available.
- Build an index from normalized Crossref Funder ID suffix to ROR records where:
  - `external_ids[].type` is `fundref`
  - the suffix appears in `external_ids[].all`
- Preserve these source details for every suggestion:
  - ROR release DOI or version metadata
  - source file name or Zenodo record
  - retrieval timestamp
  - ROR record `admin.last_modified`
  - ROR schema version

### 2. ROR API v2 as a just-in-time fallback

Use the ROR API v2 only when the local dump index is unavailable or when a single identifier needs a just-in-time lookup.

Allowed query shape:

- Search the exact normalized Crossref Funder ID suffix as a quoted query against ROR organizations.
- Accept only candidates that contain the suffix in `external_ids[type=fundref].all`.

Do not use broad name search as evidence for a replacement. Name search can help create an unresolved note, but it is not enough to propose replacing a registered identifier.

### 3. Crossref Funder API as validation only

Use Crossref funder endpoints to validate the current Crossref Funder ID, not to infer a ROR replacement.

Useful Crossref checks:

- Does the normalized suffix resolve as a known funder?
- What name does Crossref currently expose?
- Is the local stored value malformed or using an unexpected DOI form?

Crossref does not become the ROR mapping authority in this design. The replacement must still come from ROR `external_ids[type=fundref]`.

### 4. ROR / Funder Registry Overlap tool for manual review only

The ROR transition guide mentions the overlap tool as a way to inspect current mapping coverage and export a two-column CSV.

Use it only for research, diagnostics, or spot checks because:

- It is manually updated periodically.
- It carries less metadata than the ROR data dump.
- It does not provide enough provenance for automated suggestion acceptance.

### 5. Name-only matching is out of scope for this issue

If no direct registry mapping is found, do not propose a replacement from name similarity alone.

The assistant may record or display a note such as:

> No direct Crossref Funder ID to ROR mapping found. Name-based candidates require a separate review flow.

That keeps this issue focused on supportable registry mappings instead of assumptions.

## Accepted Mapping Path

For each `funding_references` row:

1. Confirm the current identifier type is `Crossref Funder ID`.
2. Normalize the current identifier to the Crossref Funder ID suffix.
3. Look up the suffix in the ROR fundref index.
4. Keep only active ROR records.
5. Require exactly one active ROR candidate.
6. Create a manual-review suggestion with provenance and confidence metadata.
7. On acceptance, replace the identifier fields with the ROR values and preserve all non-identifier funding fields.

## Fallback Outcomes

| Situation | Outcome |
| --- | --- |
| One active ROR record has the exact FundRef suffix | Create suggestion |
| No ROR record has the exact FundRef suffix | Suppress suggestion; optional unresolved note |
| Multiple active ROR records have the same suffix | Suppress suggestion; mark ambiguous |
| Only inactive or withdrawn ROR records match | Suppress suggestion; record status note |
| ROR API and dump disagree | Suppress suggestion until source is refreshed or reviewed |
| Crossref ID is malformed | Suppress suggestion; record normalization error |
| Local funder name differs from ROR names | Create suggestion only if direct registry evidence is unique; include warning note |

## Source Quality Constraints

The assistant may propose a ROR replacement only when all constraints are met:

- Mapping source is ROR data dump JSON or ROR API v2.
- Match is an exact `fundref` external ID match, not a name-only match.
- Candidate ROR record status is `active`.
- Candidate set contains exactly one active ROR record.
- Candidate record has a valid `https://ror.org/{id}` identifier.
- Candidate record includes enough provenance to identify the source snapshot or API response.

If any constraint fails, do not create an actionable replacement suggestion.

## Existing `/settings` ROR Refresh Integration

ERNIE already refreshes ROR data from `/settings`:

- `PidSetting::TYPE_ROR` points to `ror/ror-affiliations.json`.
- `/pid-settings/ror/update` dispatches `UpdatePidJob`.
- `UpdatePidJob` calls the `get-ror-ids` Artisan command.
- `get-ror-ids` downloads the latest ROR data dump from the Zenodo `ror-data` community.
- The command currently writes a reduced lookup/autocomplete file containing `prefLabel`, `rorId`, and `otherLabel` under `storage/app/private/ror/ror-affiliations.json`.

The future Crossref-to-ROR assistant should reuse that administrator-controlled refresh path instead of adding a competing ROR download mechanism.

The existing `ror/ror-affiliations.json` file must not be used as the mapping authority by itself. It is intentionally reduced for lookup/autocomplete and no longer contains the registry evidence required by Issue 784, especially ROR `external_ids[type=fundref]`, candidate `status`, `types`, and source-record metadata.

Recommended integration: extend `get-ror-ids` or add a companion processor in the same update job to write a second derived file such as `ror/ror-fundref-index.json`. That index should be built from the same downloaded ROR dump before the raw evidence is discarded.
