# ISO/TR 19115-4 JSON metadata export

Eligible resource landing pages offer **ISO/TR 19115-4 · JSON (Preview)** in
Download Metadata, alongside ISO 19115-3:2023 XML. IGSNs and Physical Objects
retain their existing formats. This export is a preview of the selected schema
implementation, not a certification or a claim of support for the entire ISO
metadata model.

## Reference and validation

The reference is the [ISO/TC 211 mdj/1.0.0 schema browser](https://schemas.isotc211.org/19115/-4/mdj/1.0.0/json/browse/#19115-4).
Original schemas and the official example are pinned under
`resources/data/scheme/iso-19115-4/1.0.0/`, from the official
[`ISO-TC211/schemas` repository at commit 1c70b6611d2af322d29d1343c12b1a670dca6acf](https://github.com/ISO-TC211/schemas/tree/1c70b6611d2af322d29d1343c12b1a670dca6acf).
The manifest records canonical URLs, schema IDs, retrieval date and SHA-256
checksums. Schema content, IDs and references are unchanged.

Each response is validated with the existing Opis JSON Schema library against
the complete `19115-4.json` entry point using JSON Schema 2020-12. The resolver
registers only the bundled schemas: the entry point, `mdj.json`, `dqc.json`, and
the TC 211 version of `Feature.json`. The latter intentionally uses
`https://geojson.org/schema/Feature.json` as its schema ID. Opis handles the
JSON Schema dialect internally. No runtime HTTP resolver is registered; unknown
schema references fail validation. The unchanged official example is validated
in tests, including with HTTP stream wrappers disabled.

The response is one GeoJSON `Feature`. ISO metadata is stored in `properties`;
the resource DOI supplies `id`. The required `conformsTo` array names the
canonical entry-point schema URI as an ERNIE convention identifying the schema
checked. The hypothetical conformance-class URIs in the official example are
not used, and no unrecorded data-quality claims are emitted.

## Availability and HTTP contract

```text
GET /{doiPrefix}/{slug}/metadata/iso-19115-4.json
Content-Type: application/json; charset=UTF-8
Content-Disposition: attachment; filename="{slug}-iso-19115-4.json"
```

The endpoint is anonymous and uses the existing public metadata throttle and
DOI/slug route rules. Drafts, unknown DOI/slug pairs, excluded resource types
and disabled formats return 404. Generation or validation failure returns a
generic 500 response without an attachment. Internal diagnostics identify the
resource and validation paths, not the complete document.

The eligibility rule uses the existing ISO type mapping, excluding
`physical-object`: dataset, collection, model, instrument, service, software,
computational-notebook, workflow, interactive-resource and image. Immutable
type slugs determine eligibility, independently of display names or templates.

`ISO19115_JSON_ENABLED=false` disables JSON only. The existing
`ISO19115_ENABLED=false` disables both ISO formats. Both default to enabled.
Configure these through the application's environment and refresh Laravel's
configuration cache as in the normal deployment workflow. Invalidate cached
landing-page render data when changing either switch. The render cache version
was advanced for the initial JSON release so existing pages receive the link.

Canonical metadata links also appear in HTTP and HTML `describedby` links with
`application/json` and the schema URI as profile metadata. Preview pages show
the existing registration-required dialog instead of offering a download;
they do not advertise public metadata representations. Template settings that
hide the metadata-download section continue to apply.

Published tombstones remain eligible for metadata downloads. They expose no
withdrawn data-download targets. Embargoed, suppressed and request-only data
links are likewise omitted. Metadata availability is independent of the
availability of the resource's data files.

## Field mapping

| ERNIE data | ISO JSON representation |
| --- | --- |
| DOI and alternate identifiers | Citation identifiers; metadata identifier points to the canonical JSON URL when published. |
| Main/alternate titles and version | Citation title, alternate titles and edition. |
| Abstract and other descriptions | Abstract and labelled supplemental information; series descriptions become citation series. HTML markup is removed and entities decoded. |
| Creators, contributors and publisher | Responsibilities, with individual/organisation discriminators and identifiers; all supported contributor roles are preserved. |
| Affiliations | Organisations containing the affiliated individual, including ROR/ORCID identifiers when present. Creator name snapshots take priority over current person names. |
| Contacts | Resource contact roles and public websites; creator/contributor email addresses stay with the contact form. The configured repository metadata contact includes its public email. |
| Funders and awards | Funder responsibilities and credits containing award number, title and URI. |
| Dates | Citation date objects, keyed by ISO date role; stored creation/revision timestamps describe the metadata. |
| Language and type | UTF-8 locale and the existing ISO scope mapping, including coverage for georeferenced images. No language is invented when missing. |
| Subjects | Keywords and thesaurus citations; existing breadcrumb fallback for vocabulary labels without a value URI. |
| Rights and access | Legal constraints, licence citations and the effective COAR access statement. |
| Formats and files | Format citations and distribution links. Assigned sizes are converted from known units to decimal megabytes for transfer size. Unknown sizes are omitted. Usable downloads without MIME types remain available. |
| Geolocation | GeoJSON geometry, place identifiers, stored bounding boxes and location descriptions. |
| Related identifiers | `IsDocumentedBy`/`IsDescribedBy` as additional documentation; `IsDerivedFrom` as lineage source citations. |

Optional empty values are omitted. Missing legacy titles or abstracts use the
empty strings explicitly allowed by the schema, never fabricated prose.
Metadata contact name and a persisted metadata timestamp are required. Array
values subject to uniqueness constraints are deduplicated, and export time is
never substituted for a stored metadata date.

Publication year is the fallback publication date; a valid Issued date can
refine it. Created, Available, Updated, Withdrawn and Accepted dates map to
creation, released, revision, unavailable and adopted. Dates are processed in
stable database-ID order; the first eligible value per role wins (an Issued
value can refine the year fallback). Year, month, day and timezone-bearing
timestamp precision is retained. Ranges and invalid dates are omitted.

Coordinates retain longitude/latitude order. Valid numeric legacy strings are
normalized to numbers. Polygon rings are closed; multiple distinct geometries
form a GeometryCollection. Bounding boxes crossing the antimeridian are split
into two polygons. Source line/polygon vertices are retained in order. Invalid
or incomplete geometry is omitted as a whole; locations without coordinates
can still supply a place or description. With no valid geometry, `geometry`
is null. No guessed global geometry or aggregate bounding box is produced.

## Deliberate limitations and updates

The selected ISO subset has no corresponding field for several ERNIE values:

- Temporal coverage intervals, including Collected/Valid ranges and per-location
  start/end dates, are omitted. An interval definition elsewhere in the schema
  does not make it a valid extent property.
- Other relation types are omitted instead of losing their relationship
  semantics through a generic citation. Related-item details have no dedicated
  mapping in this export.
- Per-keyword value URIs, classification codes and multilingual translations
  have no equivalent in the selected keyword-string representation.
- Separate elevation, locality hierarchy and in-polygon representative points
  are not promoted to invented ISO properties.
- Unmapped contributor roles, ambiguous physical sizes and detailed processing
  or data-quality reports are not invented. Affiliation relationships between
  organisations have no equivalent to the individual-within-organisation
  representation.

Open schema properties are not an invitation to add ERNIE extensions. Tests
verify meaningful field mappings as well as schema validity. Existing XML,
DataCite, editor, batch and OAI-PMH exports retain their own contracts.

Schema updates require an explicit upstream comparison, a new recorded
snapshot and checksums, and rerunning the schema, mapping, HTTP and UI tests.
Remove the Preview label only after reviewing the intended published reference;
never fetch replacement schemas during a user request.
