# IGSN Core 2.0 — v1

Schema: https://dataservices.gfz.de/schemas/igsn/2.0/core.xsd

Fixed schema: https://dataservices.gfz.de/schemas/igsn/2.0/v1/core.xsd

Documentation: https://dataservices.gfz.de/schemas/igsn/2.0/docs

Fixed documentation: https://dataservices.gfz.de/schemas/igsn/2.0/v1/docs

This package follows the IGSN Metadata Schema 2.0 published by GFZ Data Services,
AWI and HZB. The source is `anti101/IGSN-Metadata-Schema-2.0-by-GFZ-Data-Services`
at commit `e28072729362b9fe740b64e8313c9add4364f12a` (2 November 2026).
The accompanying FAIR AIMS D2 documentation is dated 31 October 2026:
https://doi.org/10.5281/zenodo.23831602.

The older, separately named `IGSN-Schema-by-GFZ-Data-Services` repository contains
an earlier schema. Its previously published `preview-1/` package remains available
with its original bytes. The short schema URL now selects v1.

The Core entry point is renamed to `core.xsd`. Its invalid upstream DataCite
import location is replaced with the relative `DataCite4.7/metadata.xsd`. These are
the only portability changes: schema declarations, namespace, qualified attributes
and all twelve DataCite dependencies are preserved. The manifest records source
URLs and both upstream and published SHA-256 checksums.

Use an XML Schema validator that can retrieve HTTPS imports. For offline use,
download all thirteen XSD files listed in `manifest.json`, retain their relative
paths, then run `xmllint --nonet --schema core.xsd sample.xml`.

The browser documentation explains differences between the published XSD and PDF.
For example, the XSD declares `sampletScheme`, allows `contributorType` to be
omitted and restricts alternate identifier types to its DataCite enumeration.
Use the documented XSD spelling and constraints when creating valid XML.

Never replace this fixed revision. Publish subsequent changes under a new revision
and retain all existing schema and documentation URLs.
