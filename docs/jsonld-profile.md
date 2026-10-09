# ERNIE DataCite JSON-LD profile

ERNIE exports DataCite Metadata Schema 4.7 attributes through a versioned JSON-LD
1.1 `attrs`/`value` profile. The public landing page separately embeds Schema.org
JSON-LD for discovery. The [DataCite crosswalk](https://support.datacite.org/docs/how-can-i-map-different-metadata-formats-to-the-datacite-xml)
describes Schema.org JSON-LD; it does not designate ERNIE's compact download profile
as a DataCite registration format. DOI registration continues to use DataCite JSON.

## Context and provenance

The default context is `${APP_URL}/metadata/contexts/datacite-4.7-v1.jsonld`.
Its exact contents are committed in `resources/data/contexts/datacite-4.7-v1.jsonld`.
The anonymous GET/HEAD endpoint returns `application/ld+json`, an ETag, Last-Modified
and a one-year public immutable cache policy. It supports conditional GET. Context
URLs use the configured public origin, including an optional deployment path,
independently of the request Host header.

`resources/data/contexts/manifest.json` records the upstream TIB URL, immutable
commit, retrieval date, upstream and profile SHA-256 checksums, CC BY 4.0 license,
attribution and adjustments. The TIB vocabulary is maintained and created by
[@selgebali](https://github.com/selgebali), published by
[TIB DataCite Linked Data](https://tibhannover.github.io/datacite/).
The derived context retains TIB's vocabulary IRIs. It is an ERNIE profile, not an
official DataCite context under `schema.datacite.org`.

The frozen context contains no remote context imports. ERNIE changes the plural
wrappers to `@nest`, represents creators and polygon vertices with ordered `@list`
containers, and applies scalar datatypes to the nested `rdf:value` literal.
Related-item `value` is a non-propagating scoped `@nest` alias so descendant literals
retain their meaning. Name identifiers remain nodes carrying a literal and scheme
attributes. Languages are explicit `dcterms:language` metadata on their nodes;
they are not silently converted to or lost as JSON-LD `@language` tags.

`DATACITE_LINKED_DATA_CONTEXT_URL` accepts a compatible absolute mirror URL. A blank
value uses the local context. An override does not change the exported structure
and is never fetched during export or import. Its contents and availability must
be verified by the deployment owner. Do not configure the unmodified TIB context:
its wrapper and value coercions do not interpret this profile correctly.

For Docker deployments, set the override in the environment file passed to Compose
(`.env.docker` locally). Development, Stage and Production forward it to the app,
queue, assessment queue and scheduler. An unset or empty override keeps the local
context. Recreate the affected containers after changing it and refresh their
Laravel configuration caches using the normal deployment process.

Once released, v1's bytes and public URL must remain available unchanged. A future
semantic change needs a new context file, manifest checksum, URL and reviewed
compatibility tests. Updating the checksum alone is not a release strategy.

## Field contract

Normative field definitions, cardinalities and enums come from
[DataCite 4.7](https://datacite-metadata-schema.readthedocs.io/en/4.7/).
JSON paths below are relative to DataCite API `data.attributes`; XML paths are
relative to `<resource>`. `[]` denotes repeated entries. Optional fields are omitted
when absent. Collections retain their cardinality through singular compact wrappers.

For full IRIs, expand these prefixes:

- `p:` = `https://w3id.org/tib/datacite/property/`
- `v:` = `https://w3id.org/tib/datacite/vocab/`
- `rdf:value` = `http://www.w3.org/1999/02/22-rdf-syntax-ns#value`
- `xsd:` = `http://www.w3.org/2001/XMLSchema#`
- `dcterms:language` = `http://purl.org/dc/terms/language`

Every scalar row uses a node whose literal is `rdf:value`. Attributes sit on that
node via `attrs`/`@nest`. Rows marked node contain their child properties directly.
The root is `https://w3id.org/tib/datacite/class/Resource`.

| DataCite JSON path                                                                                                   | XML counterpart                                                                    | Expanded property / value type                                                                                                          | Cardinality and import result                                                                           |
| -------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `doi`                                                                                                                | `identifier[@identifierType=DOI]`                                                  | Root `@id=https://doi.org/{doi}` and `p:identifier`, literal                                                                            | 0..1 in drafts, required for registration; bare DOI restored                                            |
| `creators[].name`                                                                                                    | `creators/creator/creatorName`                                                     | `p:creator` ordered list; `p:creatorName`, literal                                                                                      | Creator order and names preserved                                                                       |
| `creators[].nameType`                                                                                                | `creatorName/@nameType`                                                            | `p:nameType` → `v:nameType/{value}`                                                                                                     | Optional enum on name node                                                                              |
| `creators[].givenName`, `familyName`                                                                                 | `creator/givenName`, `familyName`                                                  | `p:givenName`, `p:familyName`, literals                                                                                                 | Optional name parts restored                                                                            |
| `creators[].nameIdentifiers[].nameIdentifier`                                                                        | `creator/nameIdentifier`                                                           | `p:nameIdentifier`, literal                                                                                                             | Repeated identifier nodes                                                                               |
| `creators[].nameIdentifiers[].nameIdentifierScheme`, `schemeUri`                                                     | `nameIdentifier/@nameIdentifierScheme`, `@schemeURI`                               | `p:nameIdentifierScheme` literal; `p:schemeURI` IRI                                                                                     | Scheme and URI restored                                                                                 |
| `creators[].affiliation[].name`                                                                                      | `creator/affiliation`                                                              | `p:affiliation`, literal                                                                                                                | Repeated affiliation nodes                                                                              |
| `creators[].affiliation[].affiliationIdentifier`, `affiliationIdentifierScheme`, `schemeUri`                         | `affiliation/@affiliationIdentifier`, `@affiliationIdentifierScheme`, `@schemeURI` | `p:affiliationIdentifier`, `p:affiliationIdentifierScheme` literals; `p:schemeURI` IRI                                                  | Identifier, scheme and URI restored                                                                     |
| `titles[].title`                                                                                                     | `titles/title`                                                                     | `p:title`, literal                                                                                                                      | Repeated titles preserved                                                                               |
| `titles[].titleType`, `lang`                                                                                         | `title/@titleType`, `@xml:lang`                                                    | `p:titleType` → `v:titleType/{value}`; `dcterms:language` literal                                                                       | Enum and language restored                                                                              |
| `publisher.name`                                                                                                     | `publisher`                                                                        | `p:publisher`, literal                                                                                                                  | Single publisher name                                                                                   |
| `publisher.publisherIdentifier`, `publisherIdentifierScheme`, `schemeUri`, `lang`                                    | `publisher` attributes                                                             | `p:publisherIdentifier`, `p:publisherIdentifierScheme` literals; `p:schemeURI` IRI; `dcterms:language` literal                          | Rich publisher attributes restored                                                                      |
| `publicationYear`                                                                                                    | `publicationYear`                                                                  | `p:publicationYear`, `xsd:gYear`                                                                                                        | Year string restored                                                                                    |
| `types.resourceType`, `resourceTypeGeneral`                                                                          | `resourceType`, `@resourceTypeGeneral`                                             | `p:resourceType`, `xsd:string`; `p:resourceTypeGeneral` → `v:resourceTypeGeneral/{value}`                                               | Free type text and enum restored, including Poster/Presentation                                         |
| `subjects[].subject`                                                                                                 | `subjects/subject`                                                                 | `p:subject`, literal                                                                                                                    | Repeated free/controlled subjects                                                                       |
| `subjects[].subjectScheme`, `schemeUri`, `valueUri`, `classificationCode`, `lang`                                    | `subject` attributes                                                               | `p:subjectScheme`, `p:classificationCode` literals; `p:schemeURI`, `p:valueURI` IRIs; `dcterms:language` literal                        | Vocabulary metadata restored                                                                            |
| `contributors[]`                                                                                                     | `contributors/contributor`                                                         | `p:contributor` node set; name uses `p:contributorName`                                                                                 | Same person/organization fields as creators; JSON order restored, RDF order not guaranteed              |
| `contributors[].contributorType`                                                                                     | `contributor/@contributorType`                                                     | `p:contributorType` → `v:contributorType/{value}`                                                                                       | Role restored on contributor node                                                                       |
| `dates[].date`, `dateType`, `dateInformation`                                                                        | `dates/date`, attributes                                                           | `p:date` literal; `p:dateType` → `v:dateType/{value}`; `p:dateInformation` literal                                                      | Date precision, intervals and explanatory text preserved                                                |
| `language`                                                                                                           | `language`                                                                         | `p:language`, `xsd:language`                                                                                                            | Single language code restored                                                                           |
| `alternateIdentifiers[].alternateIdentifier`, `alternateIdentifierType`                                              | `alternateIdentifiers/alternateIdentifier`, `@alternateIdentifierType`             | `p:alternateIdentifier` literal; `p:alternateIdentifierType` literal                                                                    | Repeated identifiers and scheme names                                                                   |
| `relatedIdentifiers[].relatedIdentifier`                                                                             | `relatedIdentifiers/relatedIdentifier`                                             | `p:relatedIdentifier`, literal                                                                                                          | Repeated identifiers preserved, including RAiD/SWHID                                                    |
| `relatedIdentifiers[].relatedIdentifierType`, `relationType`, `resourceTypeGeneral`                                  | `relatedIdentifier` attributes                                                     | Corresponding `p:` terms → corresponding `v:{term}/{value}`                                                                             | Identifier type, directed relation and resource type restored                                           |
| `relatedIdentifiers[].relatedMetadataScheme`, `schemeUri`, `schemeType`, `relationTypeInformation`                   | `relatedIdentifier` attributes                                                     | Corresponding `p:` literal terms; `p:schemeURI` IRI                                                                                     | Metadata schema permitted only for HasMetadata/IsMetadataFor; Other and relation information preserved  |
| `relatedItems[]`                                                                                                     | `relatedItems/relatedItem`                                                         | `p:relatedItem` node                                                                                                                    | Repeated structured items, with metadata nested through compact `value`                                 |
| `relatedItems[].relatedItemType`, `relationType`, `relationTypeInformation`                                          | `relatedItem` attributes                                                           | `p:relatedItemType` → `v:resourceTypeGeneral/{value}`; `p:relationType` → `v:relationType/{value}`; `p:relationTypeInformation` literal | Type, directed relation and information restored                                                        |
| `relatedItems[].relatedItemIdentifier` and its type/schema attributes                                                | `relatedItem/relatedItemIdentifier` and attributes                                 | `p:relatedItemIdentifier` literal; `p:relatedItemIdentifierType` → `v:relatedIdentifierType/{value}`; schema attributes as above        | Optional identifier object and all schema attributes restored                                           |
| `relatedItems[].titles`, `creators`, `contributors`, `publicationYear`, `publisher`                                  | Corresponding children of `relatedItem`                                            | Same properties and datatypes as resource metadata                                                                                      | Recursive metadata preserved; year normalized to DataCite JSON integer                                  |
| `relatedItems[].volume`, `issue`, `number`, `firstPage`, `lastPage`, `edition`                                       | Corresponding children of `relatedItem`                                            | Corresponding `p:` terms, `xsd:string`                                                                                                  | Bibliographic strings preserved                                                                         |
| `relatedItems[].numberType`                                                                                          | `relatedItem/number/@numberType`                                                   | `p:numberType` → `v:numberType/{value}`                                                                                                 | Optional enum on number node                                                                            |
| `sizes[]`, `formats[]`, `version`                                                                                    | `sizes/size`, `formats/format`, `version`                                          | `p:size`, `p:format`, `p:version`, `xsd:string`                                                                                         | Repeated sizes/formats and single version                                                               |
| `rightsList[].rights`                                                                                                | `rightsList/rights`                                                                | `p:rights`, literal                                                                                                                     | Repeated rights statements                                                                              |
| `rightsList[].rightsUri`, `rightsIdentifier`, `rightsIdentifierScheme`, `schemeUri`, `lang`                          | `rights` attributes                                                                | `p:rightsURI`, `p:schemeURI` IRIs; identifier/scheme literals; `dcterms:language` literal                                               | License text, identifiers, URIs and language preserved                                                  |
| `descriptions[].description`, `descriptionType`, `lang`                                                              | `descriptions/description` and attributes                                          | `p:description` literal; `p:descriptionType` → `v:descriptionType/{value}`; `dcterms:language` literal                                  | Rich text is exported without DataCite XML escaping; language and type restored                         |
| `geoLocations[].geoLocationPlace`                                                                                    | `geoLocations/geoLocation/geoLocationPlace`                                        | `p:geoLocation` node; `p:geoLocationPlace` literal                                                                                      | Repeated locations                                                                                      |
| `geoLocations[].geoLocationPoint.pointLongitude`, `pointLatitude`                                                    | `geoLocationPoint` children                                                        | `p:geoLocationPoint` node; coordinate `p:` terms, `xsd:float`                                                                           | Numeric JSON coordinates restored                                                                       |
| `geoLocations[].geoLocationBox.westBoundLongitude`, `eastBoundLongitude`, `southBoundLatitude`, `northBoundLatitude` | `geoLocationBox` children                                                          | `p:geoLocationBox` node; coordinate `p:` terms, `xsd:float`                                                                             | Four numeric bounds restored                                                                            |
| `geoLocations[].geoLocationPolygon[].polygonPoint`, `inPolygonPoint`                                                 | `geoLocationPolygon` children                                                      | Polygon node; `p:polygonPoint` ordered list; optional `p:inPolygonPoint` node; coordinate `p:` terms, `xsd:float`                       | Closed vertex order and interior point preserved                                                        |
| `fundingReferences[].funderName`, `funderIdentifier`, `awardNumber`, `awardTitle`                                    | `fundingReferences/fundingReference` children                                      | Corresponding `p:` terms, `xsd:string`                                                                                                  | Repeated funding records                                                                                |
| `fundingReferences[].funderIdentifierType`, `schemeUri`, `awardUri`                                                  | `funderIdentifier/@funderIdentifierType`, `@schemeURI`; `awardNumber/@awardURI`    | `p:funderIdentifierType` → `v:funderIdentifierType/{value}`; `p:schemeURI`, `p:awardURI` IRIs                                           | Type, identifier scheme and award URI preserved; Crossref Funder ID maps to vocabulary CrossrefFunderID |

The converter accepts `schemeUri`/`schemeURI`, `rightsUri`/`rightsURI`,
`valueUri`/`valueURI` and `awardUri`/`awardURI`. Conflicting aliases fail rather than
selecting one value silently. URI aliases and numeric coordinates are normalized
before the roundtrip comparison; free text, languages, roles and creator order are
not sorted or removed to obtain equality.

Literal `"0"` attributes and version identifiers remain intact, including classification codes, date
information and relation information. Only null attributes are removed by the
Linked-Data exporter; valid zero values are also retained by JSON/XML exports.

## Import compatibility and persistence

The upload accepts the local v1 URL, its explicitly configured compatible override,
and these historical ERNIE profile labels offline:

- `https://schema.stage.datacite.org/linked-data/context/fullcontext.jsonld`
- `https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld`

These legacy URLs are identifiers for compatibility; they are not fetched and are
not used in new downloads. A matching URL also requires the supported node structure
and attribute placement. Foreign Schema.org documents, inline/array contexts,
`@graph`, nested contexts and unsupported fields fail with HTTP 422 before creating
a draft. Converted attributes pass the existing DataCite validator. Drafts may omit
a DOI; registration validation remains stricter.

When present, the root `@id` must contain a valid DOI: either a bare DOI or an
HTTP(S) resolver URL at `doi.org` or `dx.doi.org`. Both profile recognition and
direct conversion reject unrelated URLs, invalid DOI syntax and empty/non-string
IDs before draft storage. Resolver prefixes and surrounding whitespace are removed;
the converter preserves DOI casing and the existing storage normalization
lowercases it. A draft without a DOI omits `@id` entirely.

Related-item `relationTypeInformation` now persists in
`related_items.relation_type_information` through JSON/JSON-LD/XML upload, DOI
metadata transformation and JSON/XML/JSON-LD export. Individual related-item updates
that omit the field preserve its imported value; an explicit null clears it. The
migration is additive and does not rewrite existing records. This work does not claim that the
editor/database can represent arbitrary DataCite extensions or every external
metadata arrangement; the field matrix defines the JSON-LD transport contract.

## Schema.org and ESIP

The [ESIP dataset guide](https://github.com/ESIPFed/science-on-schema.org/blob/1.3.2/guides/Dataset.md)
is a secondary discovery reference. DataCite meanings take priority. Ordered
`creator` lists remain in place; an additional `author` alias is not required.
Actual dataset files use `distribution`/`DataDownload` with their URL, MIME type and
optional size. Metadata cross-links remain separate. Schema.org exposes the selected
main title/abstract rather than guaranteeing multilingual lossless transport.

Only outgoing related-item `Cites` and `References` relations become `citation`.
Incoming citations and other relationships keep their DataCite meanings without
being turned into an outgoing citation. Physical objects/IGSN, software and the other
supported types retain their existing profiles. Object-description identity, access,
metadata-only behavior, tombstones and Signposting remain covered by the existing
integration suite. No measurement variables or access assertions are invented.

## Validation and release

Run `npm ci` with the pinned Node version, then `npm run test:jsonld` for focused
validation. The wrapper generates actual PHP exports through the Docker/Pest
workspace, copies a uniquely named report to a temporary host directory and expands
it with pinned `jsonld.js`. CI runs the same npm command using its hosted PHP setup.
No test document loader can fetch an unrecognized context URL.

The reference tests check every field above, draft/singleton/empty-attribute cases,
URI aliases and DataCite 4.7 enums, then validate actual resources for all 34 types
against DataCite JSON and the local XML XSD including its local includes. The
PhysicalObject case includes IGSN metadata. Independent graph assertions check
expected IRIs, literal values, datatypes, cardinalities and lists; successful
expansion or a converter-only roundtrip is insufficient. Node mutation tests show
that missing properties and reordered creators fail the semantic check.

The complete Schema.org context snapshot and its source/checksum live in
`tests/pest/Fixtures/JsonLd/`. Version 30.1 retains `http://schema.org/` vocabulary
IRIs even when the document's context URL uses HTTPS. Do not rewrite the expected
namespace solely to match the context URL's scheme. The domain fixture documented
in [schemaorg-resource-types.md](schemaorg-resource-types.md) adds domain checks.

Before release:

1. Run `npm run check:backend` and `npm run check:frontend`; after migration changes,
   also run the affected tests through the MySQL 9.7 wrapper.
2. Apply the additive migration with the project's normal deployment process and
   rebuild the configuration cache. Ensure `APP_URL` is the externally reachable
   HTTPS origin and remove any obsolete context override.
3. From outside the deployment, GET/HEAD the canonical context and compare its bytes
   with the manifest checksum. Confirm conditional GET and public access.
4. Fetch a published `metadata/datacite.jsonld` download and resolve its context with
   an external processor. Verify download names, media types and Signposting links.
5. If an explicit mirror is configured, compare it with the frozen profile and check
   its availability separately. Context availability is a deployment check, not a
   network dependency of normal tests.
