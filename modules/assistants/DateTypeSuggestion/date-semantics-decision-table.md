# Date Semantics Decision Table

## Current Implementation Notes

- In the current implementation, `Coverage` is handled through spatial/temporal coverage flows rather than the regular editor date list.
- In the current editor/save flows, closed date ranges are supported only for `Collected`, `Valid`, and `Other`.
- In the current editor/save/import flows, `Created` and `Updated` are handled specially. `Created` may be preserved from imports or backfilled, and `Updated` is managed on save.

## Scope And Guardrails

- This document defines semantic decision rules only. Parsing and normalization rules are documented elsewhere.
- Suggest only when the source wording or structured context makes the date semantics clear.
- A bare date or bare interval is not enough to infer the type.
- Prefer no suggestion over a speculative one.
- Reuse the existing literal value when making a correction. Do not rewrite the date just to fit a type.
- `Updated` is out of scope for this assistant.

## Type Meaning

| Type          | Reviewer meaning                                                                                         |
| ------------- | -------------------------------------------------------------------------------------------------------- |
| `Collected` | When sampling, observation, measurement, drilling, survey, or another acquisition event happened.         |
| `Coverage`  | When the date describes the time span represented by the resource content.                               |
| `Created`   | When the resource artifact itself was produced, generated, compiled, processed, digitized, or assembled. |
| `Issued`    | When the resource was first released, published, or made publicly available.                             |

## Existing `Collected`: Keep Or Correct

| Existing `Collected` context                                                                                                                                         | Assistant action                      | Reason                                                                          |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------- | ------------------------------------------------------------------------------- |
| Clear acquisition-event wording: sampling, measurement, observation campaign, expedition, survey, drilling, collection at site, lab acquisition.                       | Keep `Collected`.                     | The date describes how or when the data was obtained.                           |
| Clear represented-timespan wording: coverage, time span covered, record spans, observations from ... to ..., study period represented by the resource, archive period. | Correct `Collected` to `Coverage`.    | The date describes what period the resource is about, not when it was acquired. |
| The date is only a bare point date or bare interval, with no nearby semantic cue.                                                                                      | No suggestion.                        | The assistant must not guess between acquisition time and represented timespan. |
| The context mixes both ideas and does not clearly anchor the date to one of them.                                                                                      | No suggestion.                        | Ambiguous mixed semantics should stay for manual review.                        |
| The date appears to mean publication, release, upload, or metadata creation rather than coverage.                                                                      | No automatic correction in Task 1.    | This table only authorizes `Collected` to `Coverage` corrections.              |

## Additional `Created`

| Evidence for a missing `Created` date                                                                                                   | Assistant action                                                                      | Notes                                                                           |
| ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Explicit source wording says the resource was created, generated, compiled, processed, digitized, assembled, or exported on a given date. | Suggest additional `Created` if no existing `Created` already states that event.     | The event must describe the resource artifact, not the underlying observations. |
| A structured upstream field is already explicitly labeled `Created`.                                                                     | Suggest additional `Created` if ERNIE does not already have that same semantic date. | This is strong evidence and does not require interpretation.                    |
| The only available date is clearly a collection event.                                                                                    | No suggestion.                                                                        | `Collected` and `Created` are distinct semantics.                           |
| The only available date is clearly a publication or release event.                                                                        | No suggestion.                                                                        | That belongs to `Issued`, not `Created`.                                       |
| The candidate date could be a save timestamp, import timestamp, sync timestamp, or other system-processing timestamp.                     | No suggestion.                                                                        | Task 1 should not elevate operational timestamps into resource semantics.       |

## Additional `Issued`

| Evidence for a missing `Issued` date                                                                                 | Assistant action                                                                     | Notes                                                               |
| ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------- |
| Explicit source wording says the resource was issued, published, released, or made publicly available on a given date. | Suggest additional `Issued` if no existing `Issued` already states that event.      | The event must be the first public release of the resource.         |
| A structured upstream field is already explicitly labeled `Issued`.                                                   | Suggest additional `Issued` if ERNIE does not already have that same semantic date. | This is strong evidence and does not require interpretation.        |
| Only `publicationYear` is known, with no explicit issue or release date.                                             | No suggestion.                                                                       | Year alone is too weak for a conservative completion rule.          |
| The date reflects later modification, re-curation, or metadata update.                                                 | No suggestion.                                                                       | That is not the initial issue event.                                |
| The wording is only "available" or "embargo ended" and it is unclear whether this marks first publication.             | No suggestion.                                                                       | `Available` and `Issued` should not be conflated automatically.     |

## Correction Versus Addition

| Situation                                                                                             | Expected behavior                                                                                                                        |
| ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| The existing row has the right literal date but the wrong semantic type.                              | Suggest a correction, not an addition.                                                                                                   |
| The existing row is semantically valid, and a different lifecycle event is clearly missing.           | Suggest an addition, not a correction.                                                                                                   |
| One explicit source statement supports two different date types with the same value.                  | Suggest the missing second type only if the source explicitly names both roles.                                                          |
| A `Collected` row is clearly mis-typed coverage, and there is no separate collection-event evidence.  | Correct `Collected` to `Coverage`. Do not duplicate the same date under both types.                                                     |
| A valid collection event and a valid represented-timespan event both exist as separate statements.    | Keep the collection event as `Collected`. Handle the represented-timespan event separately; do not overwrite a valid `Collected` row.   |
| The assistant would need to invent a second event from one ambiguous statement.                       | No suggestion.                                                                                                                           |
