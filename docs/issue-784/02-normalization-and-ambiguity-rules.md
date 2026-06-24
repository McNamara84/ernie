# Issue 784: Normalization and Ambiguity Rules

These rules define how ERNIE should normalize Crossref Funder ID inputs, choose whether a ROR replacement is safe enough to propose, and update identifier type and scheme URI when a curator accepts a suggestion.

The rules apply only to `funding_references` rows with `funderIdentifierType` `Crossref Funder ID`.

## Eligibility

A funding reference is eligible for Crossref-to-ROR evaluation when all conditions are true:

- `funding_references.funder_identifier` is not empty.
- Its related `funder_identifier_types.name` or `slug` is `Crossref Funder ID`.
- It is not already typed as `ROR`.
- The row belongs to a resource that can be surfaced in the assistance UI.
- The current identifier can be normalized to a Crossref Funder ID suffix.

Rows with `ROR`, `GRID`, `ISNI`, `Other`, or missing identifier type are outside this issue.

## Current Identifier Normalization

Normalize Crossref Funder IDs into a suffix used for lookup in ROR `external_ids[type=fundref].all`.

Accepted input forms:

- `https://doi.org/10.13039/501100000780`
- `http://doi.org/10.13039/501100000780`
- `https://dx.doi.org/10.13039/501100000780`
- `doi:10.13039/501100000780`
- `10.13039/501100000780`
- `501100000780`, only when the stored type is already `Crossref Funder ID`

Normalization steps:

1. Trim whitespace.
2. Remove wrapping angle brackets if present.
3. Lowercase URL scheme and hostname for parsing.
4. Normalize `http://doi.org/`, `https://doi.org/`, and `https://dx.doi.org/` to the DOI body.
5. Remove a leading `doi:` prefix.
6. Require prefix `10.13039/` unless the stored value is a bare suffix and the type is `Crossref Funder ID`.
7. Extract the suffix after `10.13039/`.
8. Reject empty suffixes and suffixes containing spaces or URL query fragments.

Store both forms in suggestion metadata:

- `normalized_crossref_funder_id`: suffix only, for matching.
- `canonical_crossref_funder_identifier`: `https://doi.org/10.13039/{suffix}`, for display and provenance.

The local current `scheme_uri` should be interpreted as Crossref when it is either empty or one of:

- `https://doi.org/10.13039/`
- `https://www.crossref.org/services/funder-registry/`

Do not mutate the current row during discovery.

## Proposed ROR Normalization

The proposed replacement must use:

- `funder_identifier`: full ROR URL, for example `https://ror.org/00hhkn466`
- `funder_identifier_type`: `ROR`
- `scheme_uri`: `https://ror.org/`

ROR ID normalization:

1. Accept only identifiers that resolve to the canonical pattern `https://ror.org/{9-char-id}`.
2. Lowercase the ROR ID suffix.
3. Store and display the full URL.
4. Preserve the ROR display name separately from the local `funder_name`.

The acceptance action must not change:

- `funder_name`
- `award_number`
- `award_uri`
- `award_title`
- resource identity or DOI

## Evidence Levels

### Actionable: exact active registry mapping

Create a suggestion when all evidence is present:

- The normalized Crossref Funder ID suffix appears in `external_ids[type=fundref].all`.
- Exactly one active ROR record matches.
- The ROR record has `status` `active`.
- The ROR record has a valid ROR ID.
- The source snapshot or API response can be cited in provenance.

Recommended confidence:

- `confidence.level`: `high`
- `confidence.score`: `1.0`
- `confidence.evidence`: `exact_fundref_external_id_match`

### Not actionable: no direct mapping

Suppress the suggestion when there is no exact `fundref` match.

Do not replace this with a name-only ROR search. Name-only discovery belongs to a different assistant flow.

### Not actionable: ambiguous mapping

Suppress the suggestion when more than one active ROR record contains the same Crossref Funder ID suffix.

Record enough diagnostic metadata to explain the ambiguity:

- normalized suffix
- candidate ROR IDs
- candidate statuses
- candidate display names
- source snapshot

### Not actionable: inactive or withdrawn mapping

Suppress the suggestion when every exact candidate is inactive or withdrawn.

Do not automatically follow `successor` relationships for this issue. ROR documentation notes that historical correctness can matter, and successor replacement needs a separate product decision.

### Actionable with warning: local name mismatch

If the exact registry mapping is unique and active, the suggestion may still be created when the local `funder_name` differs from ROR names.

Add a warning note instead of lowering the core evidence:

- `local_name_not_found_in_ror_names`
- `local_name_matches_crossref_name_only`
- `ror_display_name_differs_from_local_name`

Curators should see this in the suggestion payload, but the mapping remains registry-grounded.

## Ambiguity Thresholds

| Check | Threshold | Result |
| --- | --- | --- |
| Exact active ROR candidates | `1` | Create suggestion |
| Exact active ROR candidates | `0` | Suppress |
| Exact active ROR candidates | `> 1` | Suppress as ambiguous |
| Candidate status | `active` only | Eligible |
| Candidate status | `inactive` or `withdrawn` only | Suppress |
| Match source | `external_ids[type=fundref].all` | Eligible |
| Match source | name search only | Suppress |
| ROR source provenance | present | Eligible |
| ROR source provenance | missing | Suppress |

## Conflict Handling

When source data conflict, prefer suppression over a risky suggestion.

Conflicts include:

- ROR dump has one mapping, but ROR API returns a different active mapping for the same suffix.
- ROR `preferred` FundRef value differs from the matched `all` value. This is allowed and should not suppress by itself, but must be recorded.
- Crossref API reports a very different funder name than both local and ROR names.
- ROR record is `active` but has no `funder` type. Since ROR states records mapped to Funder IDs are usually funders, this should be treated as suspicious and suppressed until reviewed.

## Acceptance Update Rules

On curator acceptance:

1. Lock the target `funding_references` row.
2. Re-check that it is still typed as `Crossref Funder ID`.
3. Re-check that the current normalized suffix matches the suggestion metadata.
4. Resolve the local `FunderIdentifierType` row with slug or name `ROR`.
5. Update:
   - `funder_identifier` to the proposed full ROR URL.
   - `funder_identifier_type_id` to the local ROR type id.
   - `scheme_uri` to `https://ror.org/`.
6. Delete or complete all duplicate pending suggestions for the same funding reference.
7. Preserve award fields and `funder_name`.
8. Trigger the same registered DOI sync behavior used by existing accepted ROR suggestions, if the implementation reuses that flow.

If any re-check fails, do not update the row. Remove the stale suggestion or return a clear stale-state message.

## Suppression Reasons

Use stable reason codes for logs, metrics, or future reports:

- `current_identifier_missing`
- `current_type_not_crossref_funder_id`
- `current_identifier_malformed`
- `no_exact_ror_fundref_match`
- `multiple_active_ror_matches`
- `only_inactive_or_withdrawn_ror_matches`
- `ror_candidate_missing_valid_id`
- `ror_candidate_missing_provenance`
- `source_conflict`
- `ror_candidate_not_funder_type`

