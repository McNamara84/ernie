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
