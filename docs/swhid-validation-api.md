# SWHID validation for external clients

ERNIE exposes complete SWHID validation at `POST /api/v1/identifiers/validate`.
The [OpenAPI contract](../resources/data/openapi.json) defines requests, responses and examples.
The endpoint is stateless: it does not save identifiers or query Software Heritage.

## Discover and call the validator

The identifier-type configuration endpoints (including ELMO and ELMO-MSL) include
`semanticValidation` on SWHID entries, when that type is enabled for the client.
It contains `method`, `endpoint`, `profile`, `specificationVersion` and `requiresApiKey`.
Cache this configuration together with the existing patterns and refresh it on deployment
or a profile change. Respect the client-specific identifier-type activation settings.

Existing `pattern` and `patterns.validation` fields remain syntax prechecks. A matching
regex cannot prove qualifier uniqueness, dependencies or valid ranges. Call the semantic
endpoint before treating a SWHID as valid or allowing a validated submission.

Send the request from the client's backend, using `X-API-Key` or `Authorization: Bearer`.
Keep credentials out of browser code and query parameters. Forward only the identifier
and type; do not forward an entire resource record.

```http
POST /api/v1/identifiers/validate
Content-Type: application/json
Accept: application/json
X-API-Key: <server-side ERNIE key>

{"identifierType":"SWHID","identifier":"swh:1:cnt:4d99d2d18326621ccdd70f5ea66c2e2ac236ad8b;lines=9-15"}
```

HTTP 200 returns `valid`, `normalized`, `core`, `objectType`, `qualifiers`, `resolverUrl`,
`errors`, `identifierType` and `profile`. A semantically invalid identifier also returns
200, with `valid: false`, null parsed fields and a concrete error. Display its `message`
and use `code` and `field` for programmatic handling. The current profile returns the
first error. An unqualified valid SWHID returns an empty qualifier object.

Malformed requests (including unsupported types and values longer than 2183 characters)
return 422; missing/invalid credentials return 401. The endpoint permits 60 requests per
minute per API key and IP and returns 429 with `Retry-After` when exceeded. Responses
use `Cache-Control: no-store`.

Validate on blur or explicit submission, rather than every keystroke. Ignore responses
for values the user has since changed. Debounce duplicate pending requests. If the
endpoint is unavailable, show validation as pending/unavailable and permit draft editing;
do not silently claim that the regex provides complete validation. Retry after connection
recovery or the indicated rate-limit delay.

## Profile `ernie-swhid-v1`

This profile implements [SWHID specification 1.2](https://www.swhid.org/specification/v1.2/)
with ERNIE's strict qualifier checks:

- Core version 1, types `cnt`, `dir`, `rev`, `rel`, `snp`, and 40 hexadecimal hash characters.
- Bare SWHIDs and HTTP/HTTPS wrappers from `archive.softwareheritage.org/`,
  `archive.softwareheritage.org/browse/`, `identifiers.org/` and `n2t.net/`.
- Outer whitespace, resolver wrappers and an unqualified core's trailing slash are removed;
  core casing is normalized. Qualifier order, case, percent escapes and trailing slashes
  remain intact. Invalid qualifiers are never silently removed.
- Only `origin`, `visit`, `anchor`, `path`, `lines`, `bytes`; nonempty values, valid percent
  escapes, no whitespace or duplicate qualifiers. Origin must be an absolute URI/IRI;
  path must be absolute. Percent-encoded path bytes need not be UTF-8.
- `visit` requires a snapshot core SWHID and `origin`; `anchor` requires a directory,
  commit, release or snapshot core SWHID and `path`.
- `lines` and `bytes` are mutually exclusive and restricted to files (`cnt`). They accept
  `N` or `N-M` with an ordered range, starting at 1 for lines and 0 for bytes.

PHP and TypeScript use the same [golden fixtures](../tests/fixtures/identifiers/swhid-cases.json).
Change the profile version if future rules change its acceptance semantics.

## Resource semantics

Use `IsIdenticalTo` for an archived version that identifies the described resource, and
`IsVariantFormOf` for its repository URL. ERNIE adds only valid SWHID/`IsIdenticalTo`
references to Schema.org identifiers, retaining the resource DOI first. Other relation
types remain related references. Neither successful validation nor a resolver link is
evidence that the archive object exists.

External clients must adopt the new endpoint to obtain complete validation. This ERNIE
change does not deploy client-side changes to ELMO or other repositories.
