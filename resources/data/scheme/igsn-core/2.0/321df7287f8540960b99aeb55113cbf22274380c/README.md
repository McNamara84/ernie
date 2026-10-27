# IGSN 2.0 Core preview snapshot

Upstream: https://github.com/anti101/IGSN-Schema-by-GFZ-Data-Services/tree/321df7287f8540960b99aeb55113cbf22274380c

Retrieved 2026-10-27. This is an unpublished development snapshot, not a released IGSN standard.
The namespace is exactly `urn:FAIR-AIMS:Core:1`. No Geo, Bio or IGSN 1.3 schema is included.

The 17 original files in `upstream/` retain their exact bytes and original notices.
`manifest.json` records their repository paths, Git blob IDs, SHA-256 hashes and sizes.
The public snapshot URLs omit the storage-only `upstream/` prefix.
The manifest, this README, catalog and validation entry point are ERNIE additions.

## Validation

The original Core XSD imports a Windows-local DataCite path. Its dependency is the
modified DataCite4.7 schema from this repository, not the ordinary DataCite schema.
`catalog.xml` provides mappings for processors that support XML catalogs.
PHP/libxml rejects that Windows import before invoking its resolver. Therefore
ERNIE validates via `validation.xsd`, which imports DataCite first and then Core.
It adds no declarations, restrictions or replacements. Both original schemas
remain unchanged. The runtime resolver permits only the verified local XSD files
and blocks all other file and network access.

For external validation, download the original files with the public relative
paths and `validation.xsd` into one directory, then validate against
`validation.xsd` (e.g. `xmllint --nonet --schema validation.xsd sample.xml`).
A warning about the unused Windows import may remain. Do not rely on automatic
validation from the exported `xsi:schemaLocation` alone.
When using the repository storage layout instead, resolve the public relative
paths to `upstream/` as the ERNIE resolver does.

Mapping, semantics and known limitations: `docs/igsn-2.0-core-export.md` in ERNIE.
Updates must use a new commit-addressed snapshot, with freshly verified hashes
and regression tests; existing public snapshot URLs are immutable.
