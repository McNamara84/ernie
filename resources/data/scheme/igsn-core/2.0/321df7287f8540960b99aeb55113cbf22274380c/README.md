# IGSN 2.0 Core preview snapshot

Upstream: https://github.com/anti101/IGSN-Schema-by-GFZ-Data-Services/tree/321df7287f8540960b99aeb55113cbf22274380c

Retrieved 2026-10-27. This is an unpublished development snapshot, not a released IGSN standard.
The namespace is exactly `urn:FAIR-AIMS:Core:1`. No Geo, Bio or IGSN 1.3 schema is included.

The 17 original files in `upstream/` retain their exact bytes and original notices.
`manifest.json` records their repository paths, Git blob IDs, SHA-256 hashes and sizes.
This directory is a local provenance archive, not a public schema package.
The manifest and this README are ERNIE additions.

## Validation

The original Core XSD imports a Windows-local DataCite path. Its dependency is the
modified DataCite4.7 schema from this repository, not the ordinary DataCite schema.
The portable package in `../preview-1/` replaces that import with a relative path
and renames the Core entry point to `core.xsd`. It retains every declaration and
all dependency bytes. ERNIE uses that same portable package for public schema
downloads and internal validation. No catalog or wrapper schema is required.

Public entry point: https://dataservices.gfz.de/schemas/igsn/2.0/core.xsd

Fixed revision: https://dataservices.gfz.de/schemas/igsn/2.0/preview-1/core.xsd

The former `/schemas/igsn/2.0/core/<commit>/` URLs have been removed and return
404 without redirects. This snapshot had not been released to Production.

Mapping, semantics and known limitations: `docs/igsn-2.0-core-export.md` in ERNIE.
Keep these original files unchanged for provenance. Publish changes in a new
portable revision with freshly verified hashes and regression tests.
