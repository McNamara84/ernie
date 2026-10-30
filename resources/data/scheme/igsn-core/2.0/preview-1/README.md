# IGSN Core 2.0 — portable preview 1

Canonical schema: https://dataservices.gfz.de/schemas/igsn/2.0/core.xsd

Fixed revision: https://dataservices.gfz.de/schemas/igsn/2.0/preview-1/core.xsd

This is a development preview, not an official IGSN 2.0 release. The namespace
remains `urn:FAIR-AIMS:Core:1`. Only Core is included; Geo, Bio and IGSN 1.3 are
outside this package.

## Validation

Use `core.xsd` as the schema for `urn:FAIR-AIMS:Core:1` in `xsi:schemaLocation`.
All imports and includes resolve relative to the schema URL. A validator allowed
to retrieve HTTPS schemas can load the complete dependency set without an ERNIE
resolver, XML catalog or wrapper schema.

For offline validation, download all 13 XSD files listed in `manifest.json` from
the same URL directory, preserving their relative paths. Then run:

```sh
xmllint --nonet --schema core.xsd sample.xml
```

The package includes the upstream project's modified DataCite 4.7 schema. Do not
replace it with the ordinary DataCite schema.

## Provenance

Source: https://github.com/anti101/IGSN-Schema-by-GFZ-Data-Services/tree/321df7287f8540960b99aeb55113cbf22274380c

ERNIE renamed the Core entry point to `core.xsd` and replaced its Windows-local
DataCite import with `DataCite4.7/metadata.xsd`. All declarations, validation rules
and dependency bytes remain unchanged. `manifest.json` records the source commit,
source URLs, original checksums and the checksums and sizes of published files.
Unmodified originals and their documentation remain in the ERNIE repository.

The short URL follows the revision selected by ERNIE and requires cache
revalidation. The `preview-1/` URLs are immutable; use them to pin validation to
this exact revision. Future updates receive a new revision directory. Retired
commit-addressed URLs are no longer served.
