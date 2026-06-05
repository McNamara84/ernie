# SPDX Rights Enrichment Persistence Model

Status: design contract for GitHub issue #819, under epic #772.

This document defines how SPDX-style rights enrichment suggestions should be
stored, reviewed, accepted, and exported. It intentionally avoids rebuilding
the entire rights catalog architecture. The goal is to make future assistant
work persistable without mixing global SPDX catalog data with per-resource
metadata.

## Scope

The SPDX rights enrichment assistant may suggest values for these DataCite
rights fields:

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
This means SPDX identifier, URI, and scheme URI are already persistable in the
shared catalog, but per-resource rights language is not independently
persistable yet.

## Target Persistence Model

The target model keeps SPDX catalog facts global and stores resource usage
facts on the pivot.

### Shared rights catalog: `rights`

`rights` remains the canonical license catalog. Assistant acceptance may create
or fill a missing catalog row, but it must not create one duplicate row per
resource.

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

### Resource-specific usage: `resource_rights`

`resource_rights` represents the use of a shared right by one resource. It is
the correct place for values that can vary per resource.

Target fields:

| DataCite field | Storage | Notes |
| --- | --- | --- |
| selected right | `resource_rights.rights_id` | Points to the shared `rights` row. |
| resource | `resource_rights.resource_id` | Existing resource link. |
| `lang` / `xml:lang` | `resource_rights.language` | Nullable future column. Stores the rights text language for this resource usage. |

Until `resource_rights.language` exists, exports should keep their current
fallback behavior and use the resource language when available. Once the pivot
column exists, export precedence should be:

1. `resource_rights.language`, when set.
2. `resources.language_id -> languages.code`, preserving current behavior.
3. no rights language attribute, if neither value is available.

Accepting a suggestion should attach the shared right with
`syncWithoutDetaching`, or update the existing pivot row if the right is already
attached. It must not call `sync()` with only the suggested right unless the
assistant is explicitly performing a reviewer-approved replacement action.

## Shared vs Resource-Specific Behavior

The boundary is:

- Shared `rights` data describes what the license is.
- `resource_rights` data describes how a resource uses that license.
- Assistant suggestions are temporary review artifacts. They should carry the
  complete proposed DataCite payload, but accepted suggestions should persist
  only the fields that belong in durable storage.

Examples:

- `MIT`, `MIT License`, `https://spdx.org/licenses/MIT.html`, and
  `https://spdx.org/licenses/` are shared catalog facts.
- Resource A using `MIT` with rights text language `en` is a resource-specific
  fact.
- A reviewer confidence score, evidence text, source API response, and match
  rationale are suggestion facts and stay in `assistant_suggestions.metadata`.

## Migration And Compatibility Strategy

The current schema can persist SPDX identifier, rights URI, and scheme URI. The
only required schema gap for issue #819 is per-resource rights language.

Recommended migration:

```php
Schema::table('resource_rights', function (Blueprint $table): void {
    $table->string('language', 10)->nullable()->after('rights_id');
});
```

Recommended model/export follow-up:

- Add `->withPivot('language')` to `Resource::rights()` and `Right::resources()`.
- When accepting a suggestion, pass the suggested language as pivot data when it
  is non-empty.
- Update DataCite XML export to emit `xml:lang` from pivot language first, then
  the existing resource-language fallback.
- Update DataCite JSON export to emit `lang` from pivot language first, then the
  existing resource-language fallback.
- Keep imports backward compatible by accepting the existing `licenses` array of
  SPDX identifiers. A later importer enhancement may capture rights language
  into the pivot when present.

Backward compatibility constraints:

- The migration must be nullable and must not require a data backfill.
- Existing resources continue to export the same rights language via resource
  language fallback.
- Existing editor requests keep using `licenses: string[]` until a future UI
  issue introduces per-license language editing.
- Existing DataCite/XML/JSON imports that only extract `rightsIdentifier` remain
  valid.
- The unique key on `resource_rights(resource_id, rights_id)` remains unchanged.

Rollout order:

1. Add the nullable pivot column and model pivot accessors.
2. Update XML and JSON exporters to read pivot language with fallback.
3. Implement assistant acceptance to create or reuse `rights` rows and attach
   the right through the pivot.
4. Optionally extend DataCite import/editor UI to capture per-right language.

## Suggestion Payload Contract

SPDX rights enrichment should use the generic assistant tables:

- `assistant_suggestions` for pending suggestions.
- `assistant_dismissed` for declined suggestions.

The existing `storeSuggestion()` fields should be populated as follows:

| Field | Value |
| --- | --- |
| `assistant_id` | `spdx-license-suggestion`, unless the module manifest deliberately chooses another stable ID. |
| `resource_id` | The resource being enriched. |
| `target_type` | `resource_rights`. |
| `target_id` | The resource ID when suggesting an attachment that does not have a pivot row yet. |
| `suggested_value` | Normalized SPDX identifier, for example `CC-BY-4.0`. |
| `suggested_label` | Human-readable rights text, for example `Creative Commons Attribution 4.0 International`. |
| `similarity_score` | Optional confidence score from `0.0` to `1.0`. |
| `metadata` | JSON object described below. |

`metadata` must be structured enough for reviewer display and safe acceptance:

```json
{
  "contract_version": "1.0",
  "action": "attach_right",
  "rights": "Creative Commons Attribution 4.0 International",
  "rights_uri": "https://creativecommons.org/licenses/by/4.0/",
  "rights_identifier": "CC-BY-4.0",
  "rights_identifier_scheme": "SPDX",
  "scheme_uri": "https://spdx.org/licenses/",
  "language": "en",
  "source": "spdx",
  "source_url": "https://spdx.org/licenses/CC-BY-4.0.html",
  "evidence": {
    "matched_from": "existing free-text rights field, uploaded metadata, or file hint",
    "reason": "why this SPDX license was selected"
  }
}
```

Required metadata keys:

- `contract_version`
- `action`
- `rights`
- `rights_identifier`
- `rights_identifier_scheme`
- `scheme_uri`
- `source`

Optional metadata keys:

- `rights_uri`
- `language`
- `source_url`
- `evidence`
- `raw_spdx`

Acceptance rules:

- Reject suggestions whose `rights_identifier_scheme` is not `SPDX` unless a
  future issue explicitly expands the assistant beyond SPDX.
- Normalize and validate `rights_identifier` against the SPDX catalog lookup or
  a trusted SPDX payload.
- Create or reuse one shared `rights` row keyed by `rights.identifier`.
- Attach the right to the resource without removing unrelated existing rights.
- Store `metadata.language` on `resource_rights.language` once the pivot column
  exists.
- Delete the accepted suggestion after successful persistence, following the
  existing `GenericTableAssistant` behavior.

Reviewer-facing suggestions should show at least:

- Resource title and DOI, when available.
- Current rights attached to the resource.
- Suggested rights text and SPDX identifier.
- `rightsURI`, `schemeURI`, and language when present.
- Confidence score and evidence/reason when present.

## Export Mapping

After acceptance, exports should map durable storage to DataCite as follows:

| Export field | Source |
| --- | --- |
| `rights` | `rights.name` |
| `rightsURI` | `rights.uri` |
| `rightsIdentifier` | `rights.identifier` |
| `rightsIdentifierScheme` | derived `SPDX` |
| `schemeURI` | `rights.scheme_uri` |
| `lang` / `xml:lang` | `resource_rights.language`, then resource language fallback |

This lets accepted suggestions populate the full DataCite rights payload while
keeping global SPDX catalog data separate from resource-specific usage.