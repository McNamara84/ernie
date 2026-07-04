# Issue 812 Task 2: Mapping and Normalization Rules

This document defines how the Subject Metadata Enrichment Assistant should normalize existing subject data and decide whether a mapping is trustworthy enough to suggest.

It covers legacy breadcrumb paths, normalized labels, known synonym forms, ambiguity handling, and the discovery difference between free-text and controlled-vocabulary subjects. It does not implement the assistant.

## Subject Categories

### Controlled-vocabulary subjects

A subject is controlled when `subjects.subject_scheme` is not null and not empty after trimming.

Controlled subjects are eligible for scheme-scoped enrichment. The assistant may propose missing or corrected values for:

- `subject_scheme`
- `scheme_uri`
- `value_uri`
- `classification_code`
- `breadcrumb_path`
- `language` or language metadata in the suggestion payload

Controlled discovery must search only inside the normalized current scheme. For example, a subject whose scheme normalizes to `Science Keywords` must not be matched against GEMET, EuroSciVoc, or Analytical Methods.

### Free-text subjects

A subject is free text when `subjects.subject_scheme` is null or empty after trimming.

Free-text subjects are not automatically controlled vocabulary entries. The first release may create a controlled-subject suggestion only when the free-text value itself carries strong controlled evidence:

- a recognized supported scheme prefix, such as `Science Keywords > ...`;
- a full hierarchical breadcrumb path that matches exactly one supported vocabulary concept;
- a stable concept URI from a supported source;
- a notation that is unique in a supported source and clearly belongs to one scheme.

Leaf-label-only free text, such as `water`, `forest`, or `geochemistry`, is not enough for an actionable suggestion because similar labels can exist in multiple vocabularies. Those cases should be suppressed or recorded as diagnostics for a later review flow.

## Normalization Pipeline

Apply normalization for lookup keys only. Preserve the original stored value in `metadata.current`.

1. Trim leading and trailing whitespace.
2. Decode legacy breadcrumb separators:
   - `&gt;`
   - `&gt`
   - `&amp;gt;`
   - `&amp;gt`
3. Normalize every breadcrumb separator to ` > `.
4. Collapse repeated whitespace to a single space.
5. Normalize schemes with the supported alias rules below.
6. Use case-insensitive lookup keys for scheme names and labels.
7. Do not remove meaningful punctuation inside concept labels.
8. Do not use broad stemming, plural folding, transliteration, or fuzzy matching in the first release.

The normalized breadcrumb display form is:

```text
PARENT > CHILD > LEAF
```

## Scheme Alias Rules

Scheme aliases are the only built-in synonym forms for first release unless a source cache explicitly contains source-provided alternative labels.

| Raw scheme contains | Normalize to |
| --- | --- |
| `science keywords` | `Science Keywords` |
| `platform` | `Platforms` |
| `instrument` | `Instruments` |
| `epos msl`, `msl vocabulary` | `EPOS MSL vocabulary` |
| `chronostrat` | `International Chronostratigraphic Chart` |
| `gemet` | `GEMET - GEneral Multilingual Environmental Thesaurus` |
| `analytical` and `method` | `Analytical Methods for Geochemistry and Cosmochemistry` |
| `euroscivoc`, `european science vocabulary` | `European Science Vocabulary (EuroSciVoc)` |

Unknown non-empty schemes remain unknown. They must not be coerced into a supported scheme by label similarity alone.

## Source Indexes

For each supported vocabulary cache, build these lookup indexes:

- by stable concept identifier, mapped from node `id`;
- by notation, mapped from node `notation` when present;
- by normalized full breadcrumb path;
- by normalized leaf label, only when that leaf is unique inside the normalized scheme;
- by source-provided synonym or alternative label, only if the local cache stores that evidence explicitly.

Every indexed candidate should retain:

- concept identifier;
- preferred label;
- full breadcrumb path;
- scheme;
- scheme URI;
- notation when present;
- language when present;
- source file and cache timestamp when available.

## Match Precedence

When a subject is controlled, evaluate candidates in this order:

1. Exact `value_uri` match in the normalized scheme.
2. Exact `classification_code` or source notation match in the normalized scheme.
3. Exact normalized full breadcrumb path match in the normalized scheme.
4. Unique legacy breadcrumb path match in the normalized scheme.
5. Unique source-provided synonym match in the normalized scheme.
6. Unique leaf-label match in the normalized scheme.

Prefer suppression over a risky suggestion when two candidates survive at the same precedence level.

## Legacy Breadcrumb Path Strategy

Legacy DataCite records may store a full path in `subjects.value` without `value_uri` or `scheme_uri`.

The assistant should:

1. Prefer `subjects.breadcrumb_path` when present.
2. Fall back to `subjects.value`.
3. Normalize separators and whitespace.
4. Drop a leading segment when it is only the scheme name, for example `Science Keywords >`.
5. Match the resulting path inside the normalized scheme.

An exact full-path match is high-confidence when it resolves to one concept.

Some legacy paths may refer to older vocabulary hierarchy versions. A fallback ordered-subsequence match is allowed only when:

- the subject has a supported controlled scheme or recognized scheme prefix;
- the normalized legacy path has at least two meaningful segments;
- the path segments occur in the same order in exactly one current candidate path;
- the leaf segment matches the current candidate leaf;
- no other candidate in that scheme also satisfies the same legacy path.

If more than one candidate matches, suppress the suggestion as ambiguous.

## Label and Synonym Strategy

Preferred labels from source caches are authoritative labels.

First release synonym handling is intentionally narrow:

- scheme name aliases are supported through the alias table above;
- source-provided alternative labels may be used only when the cache stores them as explicit source evidence;
- hand-curated concept synonym dictionaries are out of scope for first release;
- fuzzy label matching is out of scope for first release.

Leaf-label matching is acceptable for controlled subjects only when the subject already provides a supported scheme and the leaf is unique inside that scheme.

Leaf-label matching for free-text subjects is not actionable in first release unless another high-confidence signal identifies the scheme.

## Free-text Discovery Rules

Free-text subjects have no scheme anchor. Therefore they use a stricter strategy:

| Free-text evidence | Result |
| --- | --- |
| Recognized scheme-prefixed path, unique candidate | Actionable suggestion |
| Full breadcrumb path, unique candidate across all supported schemes | Actionable suggestion with warning |
| Stable concept URI from supported vocabulary | Actionable suggestion |
| Unique notation with an identifiable scheme | Actionable suggestion |
| Leaf label unique within one scheme but no scheme evidence | Suppress |
| Leaf label appears in multiple schemes | Suppress as ambiguous |
| No local cache for the apparent scheme | Suppress |

When a free-text subject becomes actionable, the suggestion must make the data-shape change explicit: the row would become a controlled-vocabulary subject if accepted.

## Completion Rules

A controlled subject is complete for first-release purposes when:

- `subject_scheme` normalizes to a supported scheme;
- `scheme_uri` is present and equals the canonical supported scheme URI;
- either `value_uri` or `classification_code` is present when the source provides such identifiers;
- `breadcrumb_path` is present for hierarchical vocabularies when it can be resolved;
- language can be represented as the source language or default `en`.

The assistant should not create suggestions for complete rows unless the current metadata conflicts with source evidence.

## Confidence Levels

### `high`

Use `high` only when exactly one candidate is supported by deterministic evidence:

- exact value URI match;
- exact notation match;
- exact full path match;
- unique controlled legacy path match;
- free-text path with recognized scheme prefix and unique candidate.

### `medium`

Use `medium` for non-actionable diagnostics or future UI review queues, not for first-release acceptance:

- unique controlled leaf-label match;
- unique source-provided synonym match where the source evidence is retained but weaker than URI, notation, or full path.

### `suppressed`

Suppress when:

- scheme is unsupported;
- local cache is unavailable or invalid;
- candidate count is zero;
- candidate count is greater than one;
- evidence is leaf-label-only free text;
- only fuzzy, inferred, or hand-waved label similarity exists;
- current metadata conflicts with source evidence in a way that cannot be resolved deterministically.

## Acceptance Preconditions

Before applying any future suggestion, the acceptance workflow should re-check:

- the subject row still exists;
- current `subject_scheme`, `value`, `value_uri`, and `classification_code` still match the values recorded in `metadata.current`;
- the vocabulary source snapshot or cache key still matches the suggestion provenance, or the suggestion is marked stale;
- the proposed scheme is still in the first-release supported scope;
- the proposed candidate is still unique under the same matching strategy.

The acceptance workflow should update only the fields declared in the suggestion payload contract and should preserve unrelated resource metadata.

## Suppression Reason Codes

Use stable reason codes for diagnostics, logs, or future reports:

- `unsupported_scheme`
- `missing_local_vocabulary_cache`
- `invalid_local_vocabulary_cache`
- `current_subject_already_complete`
- `no_candidate_match`
- `multiple_candidate_matches`
- `free_text_leaf_label_only`
- `ambiguous_cross_scheme_label`
- `ambiguous_legacy_path`
- `source_identifier_conflict`
- `source_notation_conflict`
- `missing_source_provenance`
- `stale_subject_state`
