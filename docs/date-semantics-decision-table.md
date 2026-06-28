# Date Semantics Decision Table

This table assumes the current ERNIE boundaries:

- `Coverage` is a recognized DataCite date type, but ERNIE currently routes it through spatial/temporal coverage handling rather than the regular editor date list.
- Explicit closed periods are currently stored only for `Collected`, `Valid`, and `Other`.
- `Created` and `Updated` are auto-managed in several import and save paths.

## Guardrails

- Suggest only when the source wording or structured context makes the date semantics clear.
- A bare date or bare interval is not enough to infer the type.
- Prefer no suggestion over a speculative one.
- Reuse the existing literal value when making a correction. Do not rewrite the date just to fit a type.
- `Updated` is out of scope for this assistant.

## Type Meaning

| Type          | Reviewer meaning                                                                                         |
| ------------- | -------------------------------------------------------------------------------------------------------- |
| `Collected` | When sampling, observation, measurement, drilling, survey, or another acquisition event happened.        |
| `Coverage`  | When the date describes the time span represented by the resource content.                               |
| `Created`   | When the resource artifact itself was produced, generated, compiled, processed, digitized, or assembled. |
| `Issued`    | When the resource was first released, published, or made publicly available.                             |

## Existing `Collected`: Keep Or Correct

| Existing`Collected` context                                                                                                                                          | Assistant action                      | Reason                                                                          |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- | ------------------------------------------------------------------------------- |
| Clear acquisition-event wording: sampling, measurement, observation campaign, expedition, survey, drilling, collection at site, lab acquisition.                       | Keep`Collected`.                    | The date describes how or when the data was obtained.                           |
| Clear represented-timespan wording: coverage, time span covered, record spans, observations from ... to ..., study period represented by the resource, archive period. | Correct`Collected` to `Coverage`. | The date describes what period the resource is about, not when it was acquired. |
| The date is only a bare point date or bare interval, with no nearby semantic cue.                                                                                      | No suggestion.                        | The assistant must not guess between acquisition time and represented timespan. |
| The context mixes both ideas and does not clearly anchor the date to one of them.                                                                                      | No suggestion.                        | Ambiguous mixed semantics should stay for manual review.                        |
| The date appears to mean publication, release, upload, or metadata creation rather than coverage.                                                                      | No automatic correction in Task 1.    | This table only authorizes`Collected` to `Coverage` corrections.            |

## Additional `Created`

| Evidence for a missing`Created` date                                                                                                    | Assistant action                                                                      | Notes                                                                           |
| ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Explicit source wording says the resource was created, generated, compiled, processed, digitized, assembled, or exported on a given date. | Suggest additional`Created` if no existing `Created` already states that event.   | The event must describe the resource artifact, not the underlying observations. |
| A structured upstream field is already explicitly labeled`Created`.                                                                     | Suggest additional`Created` if ERNIE does not already have that same semantic date. | This is strong evidence and does not require interpretation.                    |
| The only available date is clearly a collection event.                                                                                    | No suggestion.                                                                        | `Collected` and `Created` are distinct semantics.                           |
| The only available date is clearly a publication or release event.                                                                        | No suggestion.                                                                        | That belongs to`Issued`, not `Created`.                                     |
| The candidate date could be a save timestamp, import timestamp, sync timestamp, or other system-processing timestamp.                     | No suggestion.                                                                        | Task 1 should not elevate operational timestamps into resource semantics.       |

## Additional `Issued`

| Evidence for a missing`Issued` date                                                                                  | Assistant action                                                                     | Notes                                                               |
| ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------- |
| Explicit source wording says the resource was issued, published, released, or made publicly available on a given date. | Suggest additional`Issued` if no existing `Issued` already states that event.    | The event must be the first public release of the resource.         |
| A structured upstream field is already explicitly labeled`Issued`.                                                   | Suggest additional`Issued` if ERNIE does not already have that same semantic date. | This is strong evidence and does not require interpretation.        |
| Only`publicationYear` is known, with no explicit issue or release date.                                              | No suggestion.                                                                       | Year alone is too weak for a conservative completion rule.          |
| The date reflects later modification, re-curation, or metadata update.                                                 | No suggestion.                                                                       | That is not the initial issue event.                                |
| The wording is only "available" or "embargo ended" and it is unclear whether this marks first publication.             | No suggestion.                                                                       | `Available` and `Issued` should not be conflated automatically. |

## Correction Versus Addition

| Situation                                                                                             | Expected behavior                                                                                                                        |
| ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| The existing row has the right literal date but the wrong semantic type.                              | Suggest a correction, not an addition.                                                                                                   |
| The existing row is semantically valid, and a different lifecycle event is clearly missing.           | Suggest an addition, not a correction.                                                                                                   |
| One explicit source statement supports two different date types with the same value.                  | Suggest the missing second type only if the source explicitly names both roles.                                                          |
| A`Collected` row is clearly mis-typed coverage, and there is no separate collection-event evidence. | Correct`Collected` to `Coverage`. Do not duplicate the same date under both types.                                                   |
| A valid collection event and a valid represented-timespan event both exist as separate statements.    | Keep the collection event as`Collected`. Handle the represented-timespan event separately; do not overwrite a valid `Collected` row. |
| The assistant would need to invent a second event from one ambiguous statement.                       | No suggestion.                                                                                                                           |

## Basic Parsing Expectations

Only clear ISO-like values should drive suggestions.

| Pattern                                                                                    | In scope | Expectation                                                                                                                                         |
| ------------------------------------------------------------------------------------------ | -------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `YYYY`                                                                                   | Yes      | Valid point date with year precision.                                                                                                               |
| `YYYY-MM`                                                                                | Yes      | Valid point date with month precision.                                                                                                              |
| `YYYY-MM-DD`                                                                             | Yes      | Valid point date with day precision.                                                                                                                |
| `YYYY-MM-DDThh:mm`, `YYYY-MM-DDThh:mm:ss`, optionally with `Z` or `+/-hh:mm`       | Yes      | Valid datetime. Time and timezone may be preserved, but they do not determine the semantic type by themselves.                                      |
| `start/end` closed interval using the supported point-date formats on both sides         | Yes      | Valid interval. The interval still needs semantic evidence before it can be treated as`Collected` or `Coverage`.                                |
| Partial intervals such as`YYYY/YYYY`, `YYYY-MM/YYYY-MM`, or mixed-precision endpoints  | Yes      | Valid if both endpoints are clear. If day-level comparison is needed, start expands to the first day of the period and end expands to the last day. |
| Open-ended intervals such as`2020/` or `/2020`                                         | No       | Out of scope for Task 1 suggestions.                                                                                                                |
| Values that require permissive parser fallback or calendar rollover, such as`2024-02-30` | No       | Do not rely on parser correction for suggestion logic.                                                                                              |

## Unsupported Or Out Of Scope Free-Text Patterns

| Pattern                                                                                        | Why out of scope                                                                 |
| ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Locale-dependent numeric dates such as`03/04/05`                                             | Too ambiguous to normalize safely.                                               |
| Seasons or quarters such as`Spring 2020`, `Q3 2021`                                        | Require interpretation beyond the conservative rule set.                         |
| Approximate or qualified dates such as`ca. 1990`, `before 1950`, `after 2018`            | The assistant should not guess exact boundaries.                                 |
| Relative phrases such as`today`, `present`, `ongoing`, `recent years`                  | Not stable enough for deterministic metadata suggestions.                        |
| Prose ranges such as`late 1990s to early 2000s`                                              | Imprecise boundaries.                                                            |
| Discontinuous lists such as`1998, 2001, 2007`                                                | Not a single point date or a single interval.                                    |
| Named historical, archaeological, or geological periods such as`Holocene`, `Late Jurassic` | These are meaningful domain concepts, but not in-scope machine dates for Task 1. |
| Temporal clues embedded in narrative text without an explicit semantic anchor                  | Too easy to misread as collection, coverage, creation, or publication.           |
