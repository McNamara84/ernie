# Issue 812 Task 3: Suggestion Payload Contract

This contract defines the metadata shape for a future Subject Metadata Enrichment Assistant. It is compatible with ERNIE's generic `assistant_suggestions` table and keeps subject-specific evidence in `metadata`.

The contract covers proposed scheme URI, value URI, classification code, language, confidence, provenance, ambiguity, and acceptance preconditions. It does not implement discovery or acceptance.

## Generic Suggestion Fields

Recommended generic table values:

| Field | Value |
| --- | --- |
| `assistant_id` | `subject-metadata-enrichment` |
| `resource_id` | `subjects.resource_id` |
| `target_type` | `subject` |
| `target_id` | `subjects.id` |
| `suggested_value` | primary proposed controlled identifier, defined below |
| `suggested_label` | human-readable enrichment summary |
| `similarity_score` | `1.0` for first-release actionable high-confidence suggestions |
| `metadata` | object defined in this document |

`suggested_value` must be stable and deterministic for duplicate detection. Use the first available value in this order:

1. `metadata.proposed.value_uri`
2. `metadata.proposed.classification_code`
3. `metadata.proposed.scheme_uri`

If a suggestion proposes only a scheme normalization and no concept identifier, use the canonical proposed `scheme_uri`.

## Metadata Shape

```json
{
  "contract_version": "1.0",
  "issue": 812,
  "current": {
    "subject_id": 123,
    "resource_id": 456,
    "value": "EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS",
    "subject_scheme": "NASA/GCMD Earth Science Keywords",
    "normalized_subject_scheme": "Science Keywords",
    "scheme_uri": null,
    "value_uri": null,
    "classification_code": null,
    "breadcrumb_path": null,
    "language": "en",
    "is_controlled": true
  },
  "proposed": {
    "subject_scheme": "Science Keywords",
    "scheme_uri": "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords",
    "value_uri": "https://gcmd.earthdata.nasa.gov/kms/concept/forests",
    "classification_code": null,
    "breadcrumb_path": "EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS",
    "label": "FORESTS",
    "language": "en",
    "updates": {
      "subject_scheme": "Science Keywords",
      "scheme_uri": "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords",
      "value_uri": "https://gcmd.earthdata.nasa.gov/kms/concept/forests",
      "classification_code": null,
      "breadcrumb_path": "EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS",
      "language": "en"
    },
    "preserve": [
      "value",
      "resource_id"
    ]
  },
  "vocabulary": {
    "scheme": "Science Keywords",
    "scheme_uri": "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords",
    "source": "nasa_gcmd_kms",
    "source_registry_url": "https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords?format=rdf",
    "local_cache_file": "gcmd-science-keywords.json",
    "local_cache_updated_at": "2026-07-04T00:00:00Z",
    "version": null
  },
  "match": {
    "strategy": "exact_legacy_breadcrumb_path",
    "input": "EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS",
    "normalized_input": "earth science > biosphere > terrestrial ecosystems > forests",
    "matched_fields": [
      "normalized_breadcrumb_path"
    ],
    "candidate_count": 1,
    "suppression_reason": null
  },
  "provenance": {
    "source": "local_vocabulary_cache",
    "source_file": "gcmd-science-keywords.json",
    "source_retrieved_at": "2026-07-04T00:00:00Z",
    "source_generated_by": "get-gcmd-science-keywords",
    "matching_strategy": "exact_legacy_breadcrumb_path"
  },
  "confidence": {
    "level": "high",
    "score": 1.0,
    "evidence": [
      "supported_scheme",
      "exact_legacy_breadcrumb_path",
      "single_candidate",
      "source_cache_recorded"
    ]
  },
  "ambiguity": {
    "status": "none",
    "candidate_count": 1,
    "candidate_ids": [
      "https://gcmd.earthdata.nasa.gov/kms/concept/forests"
    ],
    "notes": [],
    "warnings": []
  },
  "acceptance": {
    "updates": [
      "subject_scheme",
      "scheme_uri",
      "value_uri",
      "classification_code",
      "breadcrumb_path",
      "language"
    ],
    "preconditions": [
      "target subject still exists",
      "current subject fields still match metadata.current",
      "proposed scheme remains in first-release scope",
      "matching strategy still resolves exactly one candidate"
    ],
    "stale_if": [
      "subject value changed",
      "subject scheme changed",
      "source cache was refreshed and candidate no longer resolves uniquely"
    ]
  }
}
```

## Required Metadata Fields

### `current`

Required:

- `subject_id`
- `resource_id`
- `value`
- `subject_scheme`
- `normalized_subject_scheme`
- `scheme_uri`
- `value_uri`
- `classification_code`
- `breadcrumb_path`
- `language`
- `is_controlled`

For free-text subjects, `subject_scheme`, `normalized_subject_scheme`, `scheme_uri`, `value_uri`, `classification_code`, and `breadcrumb_path` may be null, and `is_controlled` must be false.

### `proposed`

Required:

- `subject_scheme`
- `scheme_uri`
- `label`
- `language`
- `updates`
- `preserve`

For concept-level enrichment, at least one of these should be non-null:

- `value_uri`
- `classification_code`
- `breadcrumb_path`

Scheme-only suggestions are allowed when the current subject already has enough concept evidence and the missing or normalized `scheme_uri` is the only proposed write. In that case `metadata.proposed.scheme_uri` is the primary proposed value.

`updates` must contain only fields the acceptance workflow is allowed to write. The first release should preserve `value` unless a later implementation issue explicitly defines a value-rewrite workflow.

### `vocabulary`

Required:

- `scheme`
- `scheme_uri`
- `source`
- `local_cache_file`

Recommended:

- `source_registry_url`
- `local_cache_updated_at`
- `version`

### `match`

Required:

- `strategy`
- `input`
- `normalized_input`
- `matched_fields`
- `candidate_count`
- `suppression_reason`

Allowed actionable `strategy` values:

- `exact_value_uri`
- `exact_notation`
- `exact_breadcrumb_path`
- `exact_legacy_breadcrumb_path`
- `recognized_scheme_prefixed_path`

Allowed non-actionable or diagnostic `strategy` values:

- `unique_leaf_label`
- `source_synonym`
- `free_text_leaf_label`
- `none`

### `provenance`

Required:

- `source`
- `source_file`
- `matching_strategy`

Recommended:

- `source_registry_url`
- `source_retrieved_at`
- `source_generated_by`
- `local_cache_updated_at`
- `source_record_id`
- `source_record_notation`

`source` should be one of:

- `local_vocabulary_cache`
- `nasa_gcmd_kms`
- `eea_gemet_api`
- `ardc_linked_data_api`
- `eu_publications_office_euroscivoc`
- `utrecht_msl_vocabulary`

The assistant must not cite a remote source unless discovery actually used a refreshed local cache or retained enough source metadata to explain that cache.

### `confidence`

Required:

- `level`
- `score`
- `evidence`

Allowed first-release `level` values:

- `high`
- `medium`
- `suppressed`

Only `high` suggestions should be stored as actionable `assistant_suggestions` in the first release.

### `ambiguity`

Required:

- `status`
- `candidate_count`
- `candidate_ids`
- `notes`
- `warnings`

Allowed `status` values:

- `none`
- `warning`
- `suppressed`

Actionable suggestions may use `none` or `warning`. Suppressed mappings must not be stored as actionable suggestions.

## Warning Codes

Use warning codes when a suggestion is still actionable but needs curator attention:

- `free_text_promoted_to_controlled_subject`
- `scheme_alias_normalized`
- `legacy_path_resolved_to_current_hierarchy`
- `language_defaulted_to_en`
- `source_cache_timestamp_missing`
- `classification_code_not_available`

Warnings must be visible in metadata even if the first UI renders only generic suggestion cards.

## Suppressed Candidate Record Shape

Suppressed cases should not be written to `assistant_suggestions` as actionable items. If a later implementation records diagnostics, use this shape:

```json
{
  "target_type": "subject",
  "target_id": 123,
  "resource_id": 456,
  "input": "water",
  "normalized_input": "water",
  "normalized_subject_scheme": null,
  "reason": "free_text_leaf_label_only",
  "candidates": [
    {
      "scheme": "GEMET - GEneral Multilingual Environmental Thesaurus",
      "value_uri": "http://www.eionet.europa.eu/gemet/concept/9214",
      "label": "water"
    },
    {
      "scheme": "Science Keywords",
      "value_uri": "https://gcmd.earthdata.nasa.gov/kms/concept/example",
      "label": "WATER"
    }
  ],
  "source": {
    "name": "local_vocabulary_cache",
    "files": [
      "gemet-thesaurus.json",
      "gcmd-science-keywords.json"
    ]
  }
}
```

## Acceptance Validation

Before applying a suggestion, validate:

- `target_type` is `subject`.
- `suggested_value` matches the first available proposed identifier in the documented priority order.
- `metadata.current.subject_id` equals `assistant_suggestions.target_id`.
- `metadata.current.resource_id` equals `assistant_suggestions.resource_id`.
- `metadata.proposed.subject_scheme` is in the first-release scope.
- `metadata.proposed.scheme_uri` equals the canonical scheme URI for that scope.
- `metadata.confidence.level` is `high`.
- `metadata.match.candidate_count` is `1`.
- Current database values still match `metadata.current`.

If validation fails, do not update the subject. Return a stale or invalid suggestion result and leave the row unchanged.

## Field Update Rules

On acceptance, update only fields listed in `metadata.proposed.updates`:

- `subject_scheme`
- `scheme_uri`
- `value_uri`
- `classification_code`
- `breadcrumb_path`
- `language`

Do not update:

- `resource_id`
- unrelated resource metadata
- funding references, affiliations, rights, relations, or other assistant targets

By default, do not update `subjects.value`. The current export path uses `subjects.value` as the DataCite subject text, so value rewrites need a separate implementation decision outside Issue 812.
