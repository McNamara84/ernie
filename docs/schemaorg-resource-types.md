# Schema.org resource types

Public internal landing pages embed Schema.org JSON-LD in server-rendered HTML.
`SchemaOrgResourceTypeMappingService` resolves the immutable resource-type slug
using `config/schemaorg.php`. The JSON-LD exporter and both HTTP and HTML
Signposting links use this same decision. Renaming or deactivating a resource type
does not change existing resources' metadata.

The root node identifies the research object, with its DOI as `@id` when present.
The additional Signposting `AboutPage` link identifies the landing page. DataCite
JSON/XML/JSON-LD downloads keep their existing contracts; the Schema.org document
is the inline landing-page representation.

## Mapping

This is ERNIE's conservative mapping for [issue #1065](https://github.com/McNamara84/ernie/issues/1065),
based on the [DataCite resource definitions](https://datacite-metadata-schema.readthedocs.io/en/4.7/appendices/appendix-1/resourceTypeGeneral/)
and [Schema.org vocabulary](https://schema.org/docs/full.html).
The configuration records an explicit reason for every fallback. It stores full
HTTPS type URLs; JSON-LD uses the equivalent compact names with the Schema.org
context. Software retains both types, with `SoftwareSourceCode` first.

| Slug | Primary type | Profile | Fallback |
| --- | --- | --- | --- |
| audiovisual | VideoObject | media | |
| award | Thing | described-object | Awards are not necessarily monetary grants. |
| book | Book | creative-work | |
| book-chapter | Chapter | creative-work | |
| collection | Collection | creative-work | |
| computational-notebook | CreativeWork | creative-work | No general notebook type; notebooks combine text, code and outputs. |
| conference-paper | ScholarlyArticle | creative-work | |
| conference-proceeding | Collection | creative-work | |
| data-paper | ScholarlyArticle | creative-work | |
| dataset | Dataset | dataset | |
| dissertation | Thesis | creative-work | |
| event | Event | described-object | |
| image | ImageObject | media | |
| interactive-resource | CreativeWork | creative-work | Interaction alone does not establish a software application. |
| instrument | Thing | described-object | No general research instrument type; Product would imply an offered product. |
| journal | Periodical | creative-work | |
| journal-article | ScholarlyArticle | creative-work | |
| model | CreativeWork | creative-work | Models need not be 3D objects or software. |
| output-management-plan | DigitalDocument | creative-work | |
| peer-review | Review | creative-work | |
| physical-object | Thing | described-object | No common specific type for samples, substances and artifacts. |
| poster | CreativeWork | creative-work | No general research poster type; no artwork assumption. |
| preprint | ScholarlyArticle | creative-work | |
| presentation | PresentationDigitalDocument | creative-work | |
| project | Project | described-object | |
| report | Report | creative-work | |
| service | Service | described-object | |
| software | SoftwareSourceCode (+ SoftwareApplication) | software | |
| sound | AudioObject | media | |
| standard | CreativeWork | creative-work | A standard need not be a technical specification or legislation. |
| study-registration | DigitalDocument | creative-work | |
| text | CreativeWork | creative-work | Text is a literal datatype; file-specific types require more evidence. |
| workflow | CreativeWork | creative-work | Workflows need not be source code or HowTo instructions. |
| other | Thing | described-object | No evidence that the object is a CreativeWork. |

Missing, empty or unknown slugs resolve to `Thing` with the `described-object`
profile. Known fallbacks additionally preserve their canonical DataCite name as
textual `additionalType`, such as `PhysicalObject` or `Model`. Unknown slugs do not
claim membership in DataCite's enumeration. Names, filenames and free text never
select additional subtypes.

## Property profiles and identity

Creative works retain bibliographic properties and expose file representations
as `associatedMedia`/`MediaObject`. Datasets retain `distribution`/`DataDownload`.
Software retains `codeRepository`, `downloadUrl` and `associatedMedia`/`DataDownload`.
Media resources expose representations through `encoding`/`MediaObject`; each
file retains its own MIME type and optional size, including archives.

`Thing`, `Event`, `Project` and `Service` use a deliberately conservative object
profile. Properties such as `creator`, `publisher`, dates, license, coverage,
funding, citations and version are preserved on a linked `CreativeWork` under
`subjectOf`. Existing metadata-format cross-links remain alongside it. The
description has a distinct `<landing-page-url>#resource-description` identity
(or `<doi-url>#resource-description` without a landing page) and refers back to
the root through `about`. It does not reuse the object's DOI identifier.

Access text on the description explicitly refers to the described resource.
`isAccessibleForFree` is omitted there so that an object's access restriction is
not interpreted as a restriction on its public metadata. Tombstones retain type,
DOI and explanation, and suppress all file and repository assertions, including
caller-supplied content descriptors.

These object types outside the CreativeWork hierarchy intentionally extend the
[FAIR Signposting profile's CreativeWork convention](https://signposting.org/FAIR/#level1).
Schema.org validity does not imply conformance to that particular convention.

## Maintenance and validation

New seeded resource types require a reviewed mapping and independent expected
test case. A completeness test compares all seeded slugs, the configuration and
the test matrix. Select a property profile whose supported domains match the
chosen type; add a new profile if existing ones are unsuitable. Runtime exports
do not contact Schema.org. After a mapping change, invalidate cached public
render data by incrementing `CacheKey::LANDING_PAGE_RENDER_DATA` (v12 for this
release).

The tests cover all 34 types in JSON-LD, raw HTML and GET/HEAD Signposting,
with regressions for labels, inactive types, unknown types, object-description
identity, file MIME types, software and tombstones. An offline fixture extracted
from the official Schema.org 30.1 JSON-LD vocabulary checks type existence and
inherited property domains. It is intentionally not a complete range or value
validator. Its source and version are recorded in
`tests/pest/Fixtures/schemaorg-domains.json`; update its reviewed class ancestry
and property domains when expanding supported output.

On 2026-10-02, the public [Schema.org Validator](https://validator.schema.org/)
accepted server-rendered HTML examples for all 34 resource types with **zero
errors and zero warnings**. The synthetic examples included ordered creators,
all supported date fields, controlled keywords, licenses, spatial and temporal
coverage, funding, citations, downloads and a repository URL. Their common
bibliographic input is reproducible with `SchemaOrgExampleMetadata` in the test
fixtures. A focused coverage run exercised **69 of 69 executable lines** across
the new resolver, type value object and five property mappers; this measurement
does not claim complete coverage of the pre-existing exporter or application.
