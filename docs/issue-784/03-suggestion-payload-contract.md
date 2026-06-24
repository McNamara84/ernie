# Issue 784: Suggestion Payload Contract

This contract defines the suggestion data needed for a future Crossref Funder ID to ROR assistant. It is compatible with ERNIE's generic `assistant_suggestions` table while keeping the domain-specific evidence in `metadata`.

The contract covers:

- current identifier state
- proposed ROR identifier state
- provenance
- confidence
- unresolved ambiguity notes

## Generic Suggestion Fields

Recommended generic table values:

| Field | Value |
| --- | --- |
| `assistant_id` | `crossref-funder-ror-suggestion` |
| `resource_id` | `funding_references.resource_id` |
| `target_type` | `funding_reference` |
| `target_id` | `funding_references.id` |
| `suggested_value` | proposed full ROR URL |
| `suggested_label` | human-readable replacement summary |
| `similarity_score` | `1.0` for exact unique active registry mapping |
| `metadata` | object defined below |

`suggested_value` must be the value that would be written to `funding_references.funder_identifier` on acceptance.

## Metadata Shape

```json
{
  "contract_version": "1.0",
  "issue": 784,
  "current": {
    "funding_reference_id": 123,
    "resource_id": 456,
    "funder_name": "European Commission",
    "funder_identifier": "https://doi.org/10.13039/501100000780",
    "funder_identifier_type": "Crossref Funder ID",
    "scheme_uri": "https://doi.org/10.13039/",
    "normalized_crossref_funder_id": "501100000780",
    "canonical_crossref_funder_identifier": "https://doi.org/10.13039/501100000780",
    "award_number": "282625",
    "award_uri": "https://cordis.europa.eu/project/rcn/100180_en.html",
    "award_title": "MOTivational strength of ecosystem services and alternative ways to express the value of BIOdiversity"
  },
  "proposed": {
    "funder_identifier": "https://ror.org/exampleid",
    "funder_identifier_type": "ROR",
    "scheme_uri": "https://ror.org/",
    "ror_id": "https://ror.org/exampleid",
    "ror_display_name": "Example Funder",
    "ror_status": "active",
    "ror_types": ["funder"],
    "ror_record_last_modified": "2026-06-01",
    "matched_external_id": {
      "type": "fundref",
      "value": "501100000780",
      "matched_in": "external_ids.all",
      "preferred": "501100000780"
    }
  },
  "provenance": {
    "source": "ror_data_dump",
    "source_url": "https://doi.org/10.5281/zenodo.6347574",
    "source_retrieved_at": "2026-06-24T00:00:00Z",
    "source_release_doi": "10.5281/zenodo.xxxxxxx",
    "source_file": "v2.x-yyyy-mm-dd-ror-data.json",
    "ror_schema_version": "2.1",
    "matching_strategy": "exact_fundref_external_id"
  },
  "confidence": {
    "level": "high",
    "score": 1.0,
    "evidence": [
      "exact_fundref_external_id_match",
      "single_active_ror_candidate",
      "candidate_has_valid_ror_id",
      "source_snapshot_recorded"
    ]
  },
  "ambiguity": {
    "status": "none",
    "candidate_count": 1,
    "notes": [],
    "warnings": []
  },
  "acceptance": {
    "updates": {
      "funder_identifier": "https://ror.org/exampleid",
      "funder_identifier_type": "ROR",
      "scheme_uri": "https://ror.org/"
    },
    "preserve": [
      "funder_name",
      "award_number",
      "award_uri",
      "award_title"
    ],
    "preconditions": [
      "target funding reference still exists",
      "target still has Crossref Funder ID type",
      "target normalized Crossref Funder ID still matches current.normalized_crossref_funder_id"
    ]
  }
}
```

Replace `https://ror.org/exampleid` with a real canonical ROR URL. The example uses a placeholder only to show shape.

## Required Metadata Fields

### `current`

Required:

- `funding_reference_id`
- `resource_id`
- `funder_name`
- `funder_identifier`
- `funder_identifier_type`
- `normalized_crossref_funder_id`
- `canonical_crossref_funder_identifier`

Optional but recommended:

- `scheme_uri`
- `award_number`
- `award_uri`
- `award_title`

### `proposed`

Required:

- `funder_identifier`
- `funder_identifier_type`
- `scheme_uri`
- `ror_id`
- `ror_display_name`
- `ror_status`
- `matched_external_id`

Recommended:

- `ror_types`
- `ror_record_last_modified`

`proposed.funder_identifier` and `proposed.ror_id` must be the same full canonical ROR URL.

### `provenance`

Required:

- `source`
- `source_url`
- `source_retrieved_at`
- `matching_strategy`

For ROR data dump matches, also include:

- `source_release_doi` when available
- `source_file`
- `ror_schema_version`

For ROR API matches, include:

- `source` as `ror_api_v2`
- request URL or query parameters
- response timestamp
- API version

### `confidence`

Required:

- `level`
- `score`
- `evidence`

Allowed `level` values:

- `high`
- `suppressed`

This issue should create actionable suggestions only for `high` confidence.

### `ambiguity`

Required:

- `status`
- `candidate_count`
- `notes`
- `warnings`

Allowed `status` values:

- `none`
- `warning`
- `suppressed`

Actionable suggestions may have `none` or `warning`. Suppressed mappings must not be stored as actionable `assistant_suggestions`.

## Warning Notes

Use these warning codes when a suggestion is still actionable but needs curator attention:

- `local_name_not_found_in_ror_names`
- `ror_display_name_differs_from_local_name`
- `crossref_preferred_id_differs_from_matched_id`
- `source_snapshot_older_than_latest_known_release`

Warnings must be visible in the payload even if the UI initially renders them only in a generic metadata block.

## Suppressed Candidate Record Shape

Suppressed mappings should not be written to `assistant_suggestions`. If the implementation later adds logs or reports, use this shape:

```json
{
  "target_type": "funding_reference",
  "target_id": 123,
  "normalized_crossref_funder_id": "501100000780",
  "reason": "multiple_active_ror_matches",
  "candidates": [
    {
      "ror_id": "https://ror.org/example01",
      "ror_display_name": "Example One",
      "ror_status": "active"
    },
    {
      "ror_id": "https://ror.org/example02",
      "ror_display_name": "Example Two",
      "ror_status": "active"
    }
  ],
  "source": {
    "name": "ror_data_dump",
    "retrieved_at": "2026-06-24T00:00:00Z"
  }
}
```

## Acceptance Payload Validation

Before applying a suggestion, validate:

- `target_type` is `funding_reference`.
- `suggested_value` is the same as `metadata.proposed.funder_identifier`.
- `metadata.proposed.funder_identifier_type` is `ROR`.
- `metadata.proposed.scheme_uri` is `https://ror.org/`.
- `metadata.current.normalized_crossref_funder_id` still matches the current database row.
- The local `FunderIdentifierType` for `ROR` exists.

If validation fails, do not update the funding reference.
## Existing ROR Refresh Provenance

When the mapping evidence comes from ERNIE's existing `/settings` ROR refresh flow, provenance should identify both the local derived index and the upstream ROR dump:

```json
{
  "source": "ror_fundref_index",
  "source_file": "ror/ror-fundref-index.json",
  "source_generated_by": "get-ror-ids",
  "source_generated_from": "ROR Zenodo data dump",
  "source_retrieved_at": "2026-06-24T00:00:00Z",
  "matching_strategy": "exact_fundref_external_id"
}
```

Do not cite `ror/ror-affiliations.json` as the evidence source for a Crossref-to-ROR replacement unless that file is expanded to retain the required `external_ids[type=fundref]` evidence. Prefer a separate FundRef index so the existing autocomplete contract stays stable.
