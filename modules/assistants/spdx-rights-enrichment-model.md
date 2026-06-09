# SPDX Rights Enrichment Persistence Model

Status: revised design contract for the SPDX rights enrichment work under epic
#772, including import persistence and assistant suggestion boundaries.

This document defines how imported rights statements and SPDX-style rights
enrichment suggestions should be stored, reviewed, accepted, and exported. It
intentionally avoids treating raw imported rights text as trusted SPDX catalog
data. The goal is to preserve source metadata first, then let an assistant
propose a curated SPDX link that a reviewer may accept or decline.

## Scope

The import layer must be able to persist raw DataCite rights fields before any
assistant decision is made. The SPDX rights enrichment assistant may then
suggest normalized values for the same DataCite rights fields:

- `rights`: the human-readable rights text.
- `rightsURI`: the license or rights URL.
- `rightsIdentifier`: the SPDX identifier, for example `CC-BY-4.0`.
- `rightsIdentifierScheme`: fixed to `SPDX` for SPDX suggestions.
- `schemeURI`: the SPDX license scheme URI, normally `https://spdx.org/licenses/`.
- `lang` / `xml:lang`: the language of the rights text.

## Current Storage Shape

ERNIE already has a shared `rights` catalog and a many-to-many resource
relationship:

- `rights.identifier` stores the SPDX license identifier and maps to DataCite
  `rightsIdentifier`.
- `rights.name` stores the canonical rights text and maps to DataCite `rights`.
- `rights.uri` stores the license reference URL and maps to DataCite
  `rightsURI`.
- `rights.scheme_uri` stores the scheme URL and maps to DataCite `schemeURI`.
- `resource_rights.resource_id` plus `resource_rights.rights_id` attaches one
  shared right to one resource.

The export layer currently derives `rightsIdentifierScheme` as `SPDX` when a
right has an identifier. It derives rights language from the resource language.

This works only when the imported metadata already carries a known
`rightsIdentifier` or an exact `rights.name` match. It does not preserve raw
rights statements such as:

```json
{
  "rights": "CC BY 4.0",
  "rightsUri": "http://creativecommons.org/licenses/by/4.0"
}
```

For example, importing DOI `10.5880/fidgeo.2017.003` currently receives the
raw rights statement above, but no `rightsIdentifier`. Because `rights.name` in
the SPDX catalog is `Creative Commons Attribution 4.0 International`, the
current exact-name lookup does not attach a right and the original rights text
is lost from durable ERNIE storage.

## Target Persistence Model

The target model has three distinct layers:

1. Raw rights statements imported for one resource.
2. Shared, trusted rights catalog facts.
3. Temporary assistant suggestions that propose linking a raw statement to an
   SPDX catalog entry.

### Shared rights catalog: `rights`

`rights` remains the canonical license catalog. Assistant acceptance may create
or fill a missing catalog row, but it must not create one duplicate row per
resource. Raw imported strings that have not been reviewed must not be inserted
into `rights` merely to make an import pass validation.

| DataCite field | Storage | Notes |
| --- | --- | --- |
| `rights` | `rights.name` | Canonical SPDX license name or approved custom rights text. |
| `rightsURI` | `rights.uri` | SPDX reference URL or canonical rights URL. |
| `rightsIdentifier` | `rights.identifier` | SPDX identifier. Keep unique. |
| `rightsIdentifierScheme` | derived as `SPDX` | No separate column is needed for SPDX-only enrichment. |
| `schemeURI` | `rights.scheme_uri` | Normally `https://spdx.org/licenses/`. |

For SPDX suggestions, `rights.identifier` is the lookup key. Acceptance should
use an idempotent create/update flow:

1. Find `rights.identifier = suggested SPDX identifier`.
2. If it exists, use that row.
3. If it does not exist, create it with `identifier`, `name`, `uri`, and
   `scheme_uri`.
4. When the row exists but catalog fields are empty, fill empty values from the
   trusted SPDX payload. Avoid overwriting non-empty curator-maintained values
   unless a future issue explicitly defines that behavior.

### Resource-specific rights statements: `resource_rights`

`resource_rights` should represent one rights statement on one resource. A row
may be linked to the shared `rights` catalog, or it may remain an unresolved raw
statement until a reviewer accepts an assistant suggestion.

Target fields:

| DataCite field | Storage | Notes |
| --- | --- | --- |
| resource | `resource_rights.resource_id` | Existing resource link. |
| selected right | `resource_rights.rights_id` | Nullable. Points to the shared `rights` row after a trusted link exists. |
| imported `rights` | `resource_rights.rights_text` | Raw rights text from DataCite XML/JSON, legacy import, or manual entry. |
| imported `rightsURI` / `rightsUri` | `resource_rights.rights_uri` | Raw source URI, preserved even when not canonical. |
| imported `rightsIdentifier` | `resource_rights.rights_identifier` | Nullable raw identifier from source metadata. |
| imported `rightsIdentifierScheme` | `resource_rights.rights_identifier_scheme` | Nullable raw identifier scheme from source metadata. |
| imported `schemeURI` / `schemeUri` | `resource_rights.scheme_uri` | Nullable raw scheme URI from source metadata. |
| `lang` / `xml:lang` | `resource_rights.language` | Nullable. Stores the rights text language for this resource usage. |
| source marker | `resource_rights.source` | Optional value such as `datacite`, `xml`, `json`, or `legacy`. |

A row with `rights_id = null` is unresolved but still exportable as the raw
rights statement. A row with `rights_id` set is linked to the shared catalog and
may export the trusted catalog values.

For DOI `10.5880/fidgeo.2017.003`, initial import should create one unresolved
row similar to:

| Column | Value |
| --- | --- |
| `resource_id` | imported resource ID |
| `rights_id` | `null` |
| `rights_text` | `CC BY 4.0` |
| `rights_uri` | `http://creativecommons.org/licenses/by/4.0` |
| `language` | `en`, when derivable from rights XML/JSON or resource language |
| `source` | `datacite` |

Export precedence for rights language should be:

1. `resource_rights.language`, when set.
2. `resources.language_id -> languages.code`, preserving current behavior.
3. no rights language attribute, if neither value is available.

Accepting a suggestion should update the targeted unresolved `resource_rights`
row by setting `rights_id` to the accepted shared right. It should preserve the
raw imported fields for audit/evidence unless a future cleanup issue explicitly
defines a destructive replacement. It must not remove unrelated rights rows.

## SPDX Matching Strategy

The assistant must treat every imported rights field as optional evidence. A
missing `rights_uri` must not prevent matching when the imported rights text is
strong enough on its own.

Matching should run in conservative tiers:

1. Exact SPDX identifier match from `resource_rights.rights_identifier`.
2. Canonicalized URI match from `resource_rights.rights_uri`.
3. Exact canonical SPDX name match from `resource_rights.rights_text`.
4. Approved alias match from `resource_rights.rights_text`, for examples such
   as `CC BY 4.0` -> `CC-BY-4.0` and `Apache License 2.0` -> `Apache-2.0`.
5. Strict normalized-text match for well-known variants where the license family
   and version are unambiguous.
6. Unsupported/no suggestion for weak, institution-specific, commercial,
   individual, or ambiguous statements.

The URI is supporting evidence, not a required input. For example, an import
with only:

```json
{
  "rights": "CC BY 4.0"
}
```

may still produce a suggestion for `CC-BY-4.0` because `CC BY 4.0` is an
approved alias. By contrast, text such as `individual`, `commercial use only`,
or a long institution-specific permission statement should be left unresolved
unless a future issue introduces an explicit, reviewed mapping.

Free-form fuzzy matching must be intentionally narrow. It may contribute a
`similarity_score` and evidence text, but it must not turn a weak legal metadata
guess into an actionable suggestion. When in doubt, the assistant should mark
the row as unsupported internally or skip suggestion creation; the imported raw
rights row remains available for human review.

## Shared vs Resource-Specific Behavior

The boundary is:

- Shared `rights` data describes what the license is.
- `resource_rights` data describes the rights statement found on a resource,
  including unresolved raw text before review.
- Assistant suggestions are temporary review artifacts. They should carry the
  complete proposed DataCite payload, but accepted suggestions should persist
  only the fields that belong in durable storage.

Examples:

- `MIT`, `MIT License`, `https://spdx.org/licenses/MIT.html`, and
  `https://spdx.org/licenses/` are shared catalog facts.
- Resource A using `MIT` with rights text language `en` is a resource-specific
  fact.
- Resource B imported with `CC BY 4.0` and
  `http://creativecommons.org/licenses/by/4.0` is an unresolved raw rights
  statement until a reviewer accepts an SPDX enrichment suggestion.
- A reviewer confidence score, evidence text, source API response, and match
  rationale are suggestion facts and stay in `assistant_suggestions.metadata`.

## Migration And Compatibility Strategy

The current schema can persist trusted SPDX catalog facts, but it cannot persist
unresolved imported rights statements because `resource_rights.rights_id` is
required and there are no raw rights columns. The required schema gap is
therefore broader than per-resource rights language.

Recommended migration:

```php
Schema::table('resource_rights', function (Blueprint $table): void {
    // The actual migration must adjust the existing foreign key/index safely.
    $table->foreignId('rights_id')->nullable()->change();
    $table->text('rights_text')->nullable()->after('rights_id');
    $table->string('rights_uri', 512)->nullable()->after('rights_text');
    $table->string('rights_identifier', 255)->nullable()->after('rights_uri');
    $table->string('rights_identifier_scheme', 100)->nullable()->after('rights_identifier');
    $table->string('scheme_uri', 512)->nullable()->after('rights_identifier_scheme');
    $table->string('language', 10)->nullable()->after('scheme_uri');
    $table->string('source', 100)->nullable()->after('language');
});
```

The existing unique key on `resource_rights(resource_id, rights_id)` must be
revisited because unresolved rows have `rights_id = null`. Linked rights should
remain unique per resource and shared right. Raw imports should avoid duplicate
rows by comparing a normalized tuple of `resource_id`, `rights_text`,
`rights_uri`, `rights_identifier`, `rights_identifier_scheme`, and `scheme_uri`.

Recommended model/export follow-up:

- Add a first-class `ResourceRight` model for `resource_rights` so unresolved
  rows can be queried without requiring a joined `rights` row.
- Keep `Resource::rights()` and `Right::resources()` for linked catalog rights,
  but add pivot accessors for the new raw fields where needed.
- Update DataCite/XML/JSON imports to persist raw rights rows even when
  `rightsIdentifier` is missing or not recognized.
- Update DataCite XML export to emit `xml:lang` from pivot language first, then
  the existing resource-language fallback.
- Update DataCite JSON export to emit `lang` from pivot language first, then the
  existing resource-language fallback.
- Export unresolved rows from raw `resource_rights` fields and linked rows from
  the shared `rights` catalog.
- Keep imports backward compatible by accepting the existing `licenses` array of
  SPDX identifiers. Existing editor submissions that choose a known license may
  continue to create linked `resource_rights` rows directly.

Backward compatibility constraints:

- The migration must be nullable and must not require a mandatory data backfill.
- Existing resources continue to export the same rights language via resource
  language fallback.
- Existing editor requests keep using `licenses: string[]` until a future UI
  issue introduces per-license language editing.
- Existing linked rights rows remain valid; their raw columns may stay null.
- Existing DataCite/XML/JSON imports that only extract `rightsIdentifier` remain
  valid, but imports with only `rights` and `rightsUri` should no longer lose
  that information.

Rollout order:

1. Add raw rights storage to `resource_rights` and introduce a `ResourceRight`
   model.
2. Update DataCite REST, XML, JSON, and legacy imports to persist raw rights
   statements.
3. Update XML and JSON exporters to handle unresolved raw rows and linked
   catalog rows.
4. Implement assistant discovery that matches raw rights rows to SPDX catalog
   entries and stores suggestions.
5. Implement assistant acceptance to create/reuse `rights` rows and link the
   targeted `resource_rights` row.

## Suggestion Payload Contract

SPDX rights enrichment should use the generic assistant tables:

- `assistant_suggestions` for pending suggestions.
- `assistant_dismissed` for declined suggestions.

The existing `storeSuggestion()` fields should be populated as follows:

| Field | Value |
| --- | --- |
| `assistant_id` | `spdx-license-suggestion`, unless the module manifest deliberately chooses another stable ID. |
| `resource_id` | The resource being enriched. |
| `target_type` | `resource_right`. |
| `target_id` | The `resource_rights.id` row being enriched. |
| `suggested_value` | Normalized SPDX identifier, for example `CC-BY-4.0`. |
| `suggested_label` | Human-readable rights text, for example `Creative Commons Attribution 4.0 International`. |
| `similarity_score` | Optional confidence score from `0.0` to `1.0`. |
| `metadata` | JSON object described below. |

Use `resource_right` rather than `resource` for `target_type` because the
suggestion enriches one imported rights statement, not the whole resource. This
lets a resource with multiple imported rights statements receive independent
suggestions and independent reviewer decisions.

`metadata` must be structured enough for reviewer display and safe acceptance:

```json
{
  "contract_version": "1.1",
  "action": "link_right",
  "current": {
    "rights": "CC BY 4.0",
    "rights_uri": "http://creativecommons.org/licenses/by/4.0",
    "language": "en"
  },
  "proposed": {
    "rights": "Creative Commons Attribution 4.0 International",
    "rights_uri": "https://creativecommons.org/licenses/by/4.0/",
    "rights_identifier": "CC-BY-4.0",
    "rights_identifier_scheme": "SPDX",
    "scheme_uri": "https://spdx.org/licenses/",
    "language": "en"
  },
  "source": "spdx",
  "source_url": "https://spdx.org/licenses/CC-BY-4.0.html",
  "evidence": {
    "matched_from": "resource_rights.rights_text",
    "reason": "approved alias match: CC BY 4.0 -> CC-BY-4.0"
  }
}
```

Required metadata keys:

- `contract_version`
- `action`
- `current`
- `proposed`
- `proposed.rights`
- `proposed.rights_identifier`
- `proposed.rights_identifier_scheme`
- `proposed.scheme_uri`
- `source`

Optional metadata keys:

- `source_url`
- `evidence`
- `raw_spdx`

Acceptance rules:

- Reject suggestions whose `proposed.rights_identifier_scheme` is not `SPDX`
  unless a future issue explicitly expands the assistant beyond SPDX.
- Normalize and validate `proposed.rights_identifier` against the SPDX catalog
  lookup or a trusted SPDX payload.
- Create or reuse one shared `rights` row keyed by `rights.identifier`.
- Update the targeted `resource_rights` row with the accepted `rights_id`.
- Store `proposed.language` on `resource_rights.language` when it is non-empty.
- Preserve raw `current` values unless a future issue explicitly defines cleanup
  behavior.
- Delete the accepted suggestion after successful persistence, following the
  existing `GenericTableAssistant` behavior.

Declining a suggestion should leave the raw `resource_rights` row unchanged and
store the declined SPDX identifier in `assistant_dismissed`.

Reviewer-facing suggestions should show at least:

- Resource title and DOI, when available.
- Current raw rights statement attached to the resource.
- Suggested rights text and SPDX identifier.
- `rightsURI`, `schemeURI`, and language when present.
- Confidence score and evidence/reason when present.

## Export Mapping

Exports should map durable storage to DataCite as follows:

Unresolved raw `resource_rights` row:

| Export field | Source |
| --- | --- |
| `rights` | `resource_rights.rights_text` |
| `rightsURI` | `resource_rights.rights_uri` |
| `rightsIdentifier` | `resource_rights.rights_identifier`, when present |
| `rightsIdentifierScheme` | `resource_rights.rights_identifier_scheme`, when present |
| `schemeURI` | `resource_rights.scheme_uri`, when present |
| `lang` / `xml:lang` | `resource_rights.language`, then resource language fallback |

Linked `resource_rights` row after accepted SPDX enrichment:

| Export field | Source |
| --- | --- |
| `rights` | `rights.name` |
| `rightsURI` | `rights.uri` |
| `rightsIdentifier` | `rights.identifier` |
| `rightsIdentifierScheme` | derived `SPDX` |
| `schemeURI` | `rights.scheme_uri` |
| `lang` / `xml:lang` | `resource_rights.language`, then resource language fallback |

This preserves the original imported rights payload before review and lets
accepted suggestions populate the full DataCite rights payload while keeping
global SPDX catalog data separate from resource-specific usage.
