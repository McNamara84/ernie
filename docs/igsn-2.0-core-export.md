# IGSN Core 2.0 XML and public documentation

Published physical objects offer IGSN Core 2.0 XML under Download Metadata. The
public metadata endpoint is `/{doiPrefix}/{slug}/metadata/igsn-core-2.0.xml`.
Unpublished pages and ordinary resources return 404. Published tombstones retain
metadata access. Downloads are validated before a response is offered as a file.

## Authoritative sources

The released schema and the FAIR AIMS D2 PDF are the sources for the public guide.
The PDF is dated 31 October 2026, DOI
https://doi.org/10.5281/zenodo.23831602, SHA-256
`336552e0a1ee662a6c095020a25c24e254b85b1138073ab78073ac2beec914c4`.
The temporary local PDF is not a runtime dependency or part of the public package.

The PDF links to the newer repository
[IGSN-Metadata-Schema-2.0-by-GFZ-Data-Services](https://github.com/anti101/IGSN-Metadata-Schema-2.0-by-GFZ-Data-Services).
The bundled release is pinned to commit
`e28072729362b9fe740b64e8313c9add4364f12a` (2 November 2026).
The original Core XSD has SHA-256
`29e3cd836ae3ec2779599d85bb083a7118d44bb739c519dce7adb139c0d2c90b`.
Its original bytes are retained under that commit's `upstream/` directory.

The separately named older repository, `IGSN-Schema-by-GFZ-Data-Services`, still
points to the earlier October snapshot. Our former copy accurately represented
that snapshot, but it differs from the released schema linked by the PDF.

## Publication and portability

- Current schema: `https://dataservices.gfz.de/schemas/igsn/2.0/core.xsd`
- Fixed release: `https://dataservices.gfz.de/schemas/igsn/2.0/v1/core.xsd`
- Public guide: `https://dataservices.gfz.de/schemas/igsn/2.0/docs`
- Fixed guide: `https://dataservices.gfz.de/schemas/igsn/2.0/v1/docs`
- Earlier schema and guide: the same paths under `preview-1/`.

The existing `preview-1/` schema files and their checksums are unchanged. The short
alias now follows `v1` in `config/igsn_core.php`. Both packages contain all thirteen
required XSDs, a manifest and a README. The twelve adapted DataCite dependencies
are byte-identical between these source snapshots.

The new upstream Core file has an invalid DataCite `schemaLocation` containing a
namespace and the Core schema URL. The portable copy changes only that import to
`DataCite4.7/metadata.xsd` and renames the entry point to `core.xsd`. All declarations
and constraints are preserved. Its namespace remains `urn:FAIR-AIMS:Core:1`.

GET/HEAD are anonymous, with ETags, CORS and no session cookies for schema files.
Short schema aliases require revalidation; fixed XSD revision URLs are immutable.
External validators can follow relative imports/includes. For offline use, retain
the manifest's directory structure and run:

```sh
xmllint --nonet --schema core.xsd sample.xml
```

Never overwrite a published fixed revision or rewrite it to approximate the PDF.
Publish subsequent changes under a new revision and retain older URLs.

## Browser guide

The public Laravel/Inertia page combines a plain English introduction, a nested
schema tree, search over elements and attributes, required/optional filters,
per-element deep links, actual enum/fixed/default values, and copyable XML
examples. Phone layouts use a collapsible navigation panel. The guide links to
its schema, source, publication and manifest. Landing-page downloads link to it.

`IgsnCoreDocumentationService` reads the exact checksummed local XSD dependency
set selected by the requested revision. It resolves imported types, namespaces,
attributes and child elements. Structural rules are not copied from a separate
hand-maintained table. Required children of optional parents are labelled in
context. The guide makes remaining PDF/XSD differences visible beside the
relevant fields and in its source notes.

All interface text and simple English explanations are stored separately in
`resources/data/schema-docs/igsn-core/2.0/en.json`. Future translations can use the
same keys; `igsn_core.documentation_locale` selects a content file with an English
fallback. Technical XML names, identifiers and enum values remain unchanged.

Minimal and extended examples are public under
`/schemas/igsn/2.0/v1/docs/examples/{minimal|full}.xml`, with matching aliases and
older-snapshot examples. They are independently authored fixtures checked against
the exact schema revision. The upstream full example is obsolete and is not used.
The guide is a reference, not a public XML-upload or validation service.

## Export mapping for the released schema

| ERNIE metadata | Core XML |
| --- | --- |
| DOI | `identifier` with the full DOI and fixed `igsn:identifierType="DOI"`. |
| Authoritative local names and titles | Repeated `name`; preserve supported types and English language metadata. |
| Creators and contributors | `agents/contributor/contributorName`, retaining snapshots, order, identifiers and affiliations. A uniquely matched SampledBy creator becomes `DataCollector`. A generic creator gets no invented ProjectLeader role. Existing known contributor roles are preserved. |
| Publisher | `agents/publisher` with its real identifier, supported scheme and scheme URI. |
| Resource type | `entityTypeClassifications/resourceType`, based on the resource's actual type and `resourceTypeGeneral="PhysicalObject"`. |
| Stored sample type | The separate optional `sampleType` field. |
| Material and classifications | Material statements with uniquely matched controlled vocabulary information where available. No alternate-material role is inferred. |
| Descriptions | Supported type and language, retaining the subject of Other descriptions. Strip markup once and escape XML once. |
| Dates and publication year | `dates/date` and `dates/publicationYear`; retain known date precision and intervals. Never invent a day or year. |
| Current/historical archives | Not fields in the released Core XSD. Omit and diagnose; do not rewrite stored metadata. |
| Identifiers and relationships | Supported alternate/parent/related identifiers, preserving scheme-aware deduplication and parent privacy. |
| Locations | Supported places, points, boxes and polygons; preserve zero coordinates, close valid polygon rings and use the supported coordinate system. No inference of archive coordinates. |
| Funding | Real funder names and supported identifiers, schemes and award information. |

The source builder and XSLT have been updated for v1. Core attributes are qualified
with `igsn:`. Attributes on imported DataCite nameIdentifier/affiliation types are
unqualified; imported coordinate children use `daci:`. Contact emails and private
parents remain outside the export. Core is a shared foundation; domain profiles
can add requirements for complete descriptions or registration workflows.

## Remaining PDF/XSD differences

- The XSD spells the sample-type scheme attribute `sampletScheme`; the PDF says
  `sampleTypeScheme`. Valid XML uses the actual XSD name.
- The PDF describes contributorType as mandatory; the XSD permits omission.
- The PDF repeats sampleType in an example; the XSD allows at most one.
- Polygon coordinateSystem belongs on each polygonPoint in this XSD.
- Alternate identifier types follow the included DataCite enumeration.
- Several language fields permit only `en`; the guide shows actual allowed values.

These facts are documented rather than silently changing the upstream grammar.

## Validation

Use the repository wrappers and pinned toolchain:

```sh
npm run test:php -- tests/pest/Feature/IgsnCoreDocumentationTest.php tests/pest/Feature/Services/IgsnCoreXmlExporterTest.php tests/pest/Feature/LandingPages/IgsnCoreMetadataTest.php
npm run test:run -- tests/vitest/pages/__tests__/igsn-core-docs.test.tsx tests/vitest/pages/LandingPages/__tests__/IgsnCoreDownload.test.tsx
npm run test:e2e:devstack -- critical/igsn-core-docs.spec.ts
npm run check:backend
npm run check:frontend
```

On Windows PowerShell use `npm.cmd` when forwarding command options. Browser tests
cover anonymous access, attribute search, deep links, example downloads and phone
layout. Backend tests cover XSD provenance, old revision preservation, structure,
namespace handling and real validation of every downloadable example.
