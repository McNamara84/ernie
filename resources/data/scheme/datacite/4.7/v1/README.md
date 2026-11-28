# ERNIE DataCite 4.7 — revision v1

This package publishes ERNIE's DataCite JSON validation schema and JSON-LD 1.1
context. These are ERNIE profiles, not official DataCite publications. The official
DataCite XML schema remains at https://schema.datacite.org/meta/kernel-4.7/metadata.xsd.

## Public URLs

| Artifact | Current revision alias | Immutable v1 URL |
| --- | --- | --- |
| JSON Schema | https://dataservices.gfz.de/schemas/datacite/4.7/schema.json | https://dataservices.gfz.de/schemas/datacite/4.7/v1/schema.json |
| JSON-LD context | https://dataservices.gfz.de/schemas/datacite/4.7/context.jsonld | https://dataservices.gfz.de/schemas/datacite/4.7/v1/context.jsonld |

Both directories also provide `manifest.json` and this `README.md`. GET and HEAD
are anonymous and support ETags and conditional requests. Browser clients may
retrieve the artifacts across origins. Aliases require revalidation; versioned
URLs are immutable. Use the versioned URL in JSON-LD `@context` and for repeatable
validation. The older `/metadata/contexts/datacite-4.7-v1.jsonld` URL continues to
serve the exact same context bytes.

## Validate DataCite JSON

Use a JSON Schema Draft 2020-12 validator. `schema.json` validates the attributes
object: for ERNIE/DataCite JSON API exports, select `data.attributes` first. It does
not validate the API envelope itself. Draft records may omit `doi`; DataCite
registration additionally requires a DOI and the other API checks.

The public schema expresses publication years and DataCite W3CDTF dates, including
open or closed RKMS-ISO8601 ranges, with standard `pattern` constraints. Calendar
dates, leap years, time components and timezone offsets up to +/-14:00 are checked
without ERNIE-specific format extensions. Enable standard `uri` format validation
in your validator to check URI-valued fields as well.

For example, install Python's validator and its format-checking dependencies with
`pip install "jsonschema[format]"` and run this script with your local
`metadata.json` file:

```python
import json
from urllib.request import urlopen
from jsonschema import Draft202012Validator, FormatChecker

with urlopen("https://dataservices.gfz.de/schemas/datacite/4.7/v1/schema.json") as response:
    schema = json.load(response)
with open("metadata.json", encoding="utf-8") as source:
    document = json.load(source)
attributes = document["data"]["attributes"] if "data" in document else document
Draft202012Validator.check_schema(schema)
Draft202012Validator(schema, format_checker=FormatChecker()).validate(attributes)
print("Valid ERNIE DataCite 4.7 attributes")
```

## Process DataCite JSON-LD

`context.jsonld` is a JSON-LD 1.1 context for ERNIE's compact `attrs`/`value`
profile. A JSON-LD 1.1 processor such as jsonld.js can retrieve it and expand the
document. It contains no remote context imports. Expansion checks JSON-LD
processing and interprets terms; it does not check DataCite mandatory fields or
cardinalities. For those checks, convert the supported ERNIE profile to DataCite
attributes and validate with `schema.json`, as ERNIE's importer does.

The context derives from TIB DataCite Linked Data, maintained and created by
@selgebali, under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/).
`manifest.json` retains the source commit, source and profile checksums,
attribution and the profile's adjustments. The context bytes match ERNIE's
previously published v1 context exactly.

## Integrity and updates

`manifest.json` lists SHA-256 checksums and byte counts for the downloadable files.
For offline use, save the schema or context locally and supply it explicitly to
your validator/processor. The schema's `$id` remains its immutable Production URL.

Never overwrite a published revision. Publish semantic changes in a new revision
directory and retain old files and URLs. The short alias may then select the new
revision. IGSN Core is separately available at
https://dataservices.gfz.de/schemas/igsn/2.0/core.xsd and its fixed `v1/`
revision, including all required relative XSD dependencies.
