# DCAT-AP resource exports

ERNIE exports **DCAT-AP 3.0.1 (EU base profile)** as RDF/XML from published resource landing pages and `/oai-pmh?verb=ListRecords&metadataPrefix=dcat_ap`. Both transports use the same Resource-based exporter. There is no DataCite XML crosswalk and no runtime vocabulary download.

Version 3.0.1 is the latest stable DCAT-AP 3 release verified on 2026-11-09; 3.0.2 is still a draft. See the [official release list](https://github.com/SEMICeu/DCAT-AP) and [specification](https://semiceu.github.io/DCAT-AP/releases/3.0.1/). Update the profile, mapping, local assets, UI version and tests together when adopting a later recommendation.

## Availability

Eligible types are `dataset`, `collection`, `model`, `image` and `sound`. Each is a `dcat:Dataset`; collections are not automatically DatasetSeries. Physical Objects and every Resource with IGSN metadata are excluded. A published landing page, valid DOI, main title, Abstract and persisted metadata timestamp are required. Other description types never substitute for an Abstract.

The public attachment is `/{doiPrefix}/{slug}/metadata/dcat-ap.rdf`, with `application/rdf+xml; charset=UTF-8`. Download Metadata, HTML links and the HTTP Link header expose the same canonical URL and versioned profile. Draft previews show the existing unavailable-download dialog. Disabling the flag removes the format from both transport paths and discovery, including cached landing pages.

## Rollout and recovery

1. Apply the additive migration through the normal deployment process. Locally: `npm run artisan -- migrate`.
2. Review `npm run artisan -- dcat-ap:audit --format=json` or `--format=csv`. The audit works while exports are disabled and changes neither resource metadata nor harvest history. Error and warning codes distinguish blocking defects from permitted omissions.
3. During the deployment maintenance window, set `DCATAP_ENABLED=true`, refresh configuration and run `npm run artisan -- dcat-ap:sync` **before reopening public traffic**. Synchronization gives newly available historical resources a current OAI datestamp, so incremental harvesters discover them.
4. Check `ListMetadataFormats`, a resource download, `GetRecord`, pagination and the audit report.

Catalog URI, title, description and publisher are configurable with `DCATAP_CATALOG_URI`, `DCATAP_CATALOG_TITLE`, `DCATAP_CATALOG_DESCRIPTION` and `DCATAP_CATALOG_PUBLISHER`; defaults identify GFZ Data Services. Invalid catalog configuration is an operational error, never a reason to retire all records. The fallback contact uses `ISO19115_METADATA_CONTACT_*`.

For Docker, set the flag and optional catalog overrides in the Compose environment file (locally `.env.docker`; examples are in `.env.docker.example`). All dev, stage and production PHP services receive the same values, including the queues and scheduler. Recreate those containers after changing these settings; restarting an existing container does not update its environment. In stage/production, the image's application files are immutable, so editing an application `.env` is not the configuration path. Keep public traffic closed until configuration is refreshed and `dcat-ap:sync` has completed.

To roll back exposure, set `DCATAP_ENABLED=false` and refresh configuration. Preserve `oai_pmh_format_records`. Run `dcat-ap:sync` before re-enabling public access. Also run synchronization after deliberate query-builder/SQL maintenance that bypasses model events, after changing catalog configuration or after incrementing `dcatap.revision` for a mapping/vocabulary change. Application writers must use Eloquent aggregate transactions or call `DcatApRecordService::schedule()` before their transaction commits.

## Mapping decisions

- One Catalog, CatalogRecord and catalogued Dataset. Dataset identity is the DOI URL; record identity is the canonical RDF URL plus `#record`. Serialization is deterministic and contains no request-time timestamps or generated blank-node IDs.
- Main titles and Abstracts retain explicit languages. Abstract paragraphs of one language are combined. Methods become a provenance statement. Other descriptions are omitted. Scientific text and Unicode are preserved.
- Creators retain stored name snapshots and valid ORCID/ROR identifiers. Contacts follow ISO 19115-3 XML: marked creators and ContactPerson contributors, including their valid email. If none has a usable email, the configured GFZ contact is added.
- All datasets receive EU themes TECH and ENVI. ENER requires the controlled GCMD geothermal-energy or energy-production/use hierarchy, or a descendant of GEMET's energy node. A free-text energy keyword and the GEMET top node alone do not qualify.
- Issued falls back to the publication year; Updated and Collected preserve valid precision and open range boundaries. The record timestamp is separate from curated dataset dates. Invalid optional values are omitted.
- Geographic locations use CRS84 WKT in longitude/latitude order, including zero coordinates, closed polygons and split antimeridian boxes. Place-only locations receive no invented coordinates.
- Relations use `dct:isReferencedBy`, `foaf:page`, `dct:source` or `dcat:hasVersion` where specified in #1375. Other relations retain the target plus a qualified DataCite 4.7 role. PHP and TypeScript identifier resolution share regression fixtures, including SWHID.
- Existing content links become distributions with known byte sizes. IANA membership is checked against the local registry; a known non-registered type such as `application/x-netcdf` retains its download and a uniquely evidenced EU format, while `dcat:mediaType` is omitted. Without usable content links the distribution points to the landing page. Embargoes and download suppression are respected.
- One distinct license is exported on each distribution. Multiple distinct licenses remain together in a rights statement, with no arbitrary selection and an `ambiguous_licenses` audit warning. Identical identifiers are deduplicated; different HTTP/HTTPS or trailing-slash URLs are not assumed equivalent. COAR access-right statements are not treated as licenses.
- Access rights map open → PUBLIC, restricted/embargoed → RESTRICTED, metadata-only → NON_PUBLIC. Unknown access rights are omitted. Tombstones retain metadata and a withdrawal notice, use NON_PUBLIC, and point only to the internal tombstone page without direct downloads.

These choices implement [#1374](https://github.com/McNamara84/ernie/issues/1374), [#1375](https://github.com/McNamara84/ernie/issues/1375) and [#1376](https://github.com/McNamara84/ernie/issues/1376), including the five stakeholder decisions recorded in the implementation plan. They do not change DOI versioning or DataCite synchronization.

## OAI-PMH lifecycle

`dcat_ap` is registered independently of ISO. Identifier-specific format discovery includes previously available deleted records. Unknown identifiers return `idDoesNotExist`; known identifiers that never supported DCAT return `cannotDisseminateFormat`.

`oai_pmh_format_records` stores availability, identity, first availability, datestamp, last eligible sets, type, mapping revision and a content fingerprint. It intentionally survives resource deletion. Loss of a required Abstract, an unsupported type change, DOI replacement, unpublishing and deletion create persistent format-specific deleted headers. A public tombstone with required metadata stays active. Restoring eligibility revives the same identity with a new datestamp. The history of existing formats remains separate.

Synchronization and harvest requests retire active history whose resource link is missing, including a `NULL` resource ID. They preserve its identifier, DOI and historical sets, advance the deletion datestamp once, and invalidate affected inventories. `GetRecord` then returns a deleted header; interrupted lists require a fresh harvest. Synchronization and `GetRecord` restore a detached link when an eligible published resource still owns the DOI, even if its metadata fingerprint is unchanged.

Observers reconcile the final committed aggregate, including child metadata, download assignments and shared publisher/party/vocabulary changes. Rollbacks and intermediate replacement of child collections do not create withdrawals. Ordinary page-view counters do not advance metadata datestamps.

Within a transaction, repeated child changes are combined into one reconciliation per resource after the outer commit. Touch requests are combined only from surviving transactions; rolling back a savepoint also discards its requests and those of its committed descendants. Writes outside a transaction reconcile immediately.

Harvest inventories freeze identities with the existing database snapshot mechanism. Eligibility, identity and set transitions invalidate affected inventories. Retrying an otherwise unchanged token preserves page membership. A record moving outside a bounded date interval requires a fresh request. Clients must handle `badResumptionToken`, retry from their checkpoint, use overlapping incremental windows and deduplicate identifiers. Historical sets remain discoverable.

The issue-specified format namespace is `http://data.europa.eu/r5r/`. The RDF/XML root uses the RDF namespace. The public `/schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd` validates that transport envelope; it does **not** claim to validate DCAT semantics.

## Validation and local assets

`npm run test:dcat-ap` generates actual PHP exports and OAI responses from three pinned public DataCite snapshots (`10.5880/intermagnet.1991.2016`, `10.5880/gfz.b103-15021.1`, `10.5880/gfz_orbit/rso`) and synthetic metadata covering content links, ambiguous rights, non-IANA media types and tombstones. An isolated container then runs without network access:

- RDF/XML envelope validation, independent Jena and RDFLib parsing, and graph-isomorphism checks between parsers and transport paths;
- official pinned DCAT-AP SHACL, explicit EU/IANA vocabulary membership checks and a negative control with a missing required description;
- a full-graph SHACL report plus an exchange-scoped report. The latter limits DatasetShape targets to the dataset actually catalogued in the exchange. External typed Dataset references are not falsely supplied with titles or descriptions. Other constraints remain unchanged. A successful exchange-scoped check is not reported as full-graph conformance.

Reports are written to `storage/logs/dcat-ap/`. The first validator-image build needs network access; subsequent semantic validation is offline. Runtime PHP has no RDF/Python/Java dependency. The complete backend wrapper and the serial CI job include semantic validation. `npm run test:php:mysql-sensitive:oai-pmh` exercises format history and pagination on the pinned MySQL 9.7 server. Frontend tests cover download visibility, accessible labels, preview interaction and harvester documentation.

Browser checks run with `npm run test:e2e:devstack -- critical/landing-page-dcat-ap.spec.ts`. On Docker Desktop, use the documented `ERNIE_PLAYWRIGHT_ASSETS=build` and `ERNIE_PLAYWRIGHT_BACKEND=native` settings. They exercise the real template with controlled metadata props in Chromium, Firefox and WebKit, including narrow layouts, light/dark contrast and keyboard focus restoration. Actual export responses and eligibility are covered by the PHP and independent RDF checks.

The asset manifest records source URLs and SHA-256 checksums, including the public DataCite fixture snapshots. Vocabulary snapshots are stored with deterministic gzip compression. `python scripts/dcat-ap/build-vocabulary-index.py` rebuilds the runtime index locally from those snapshots. Review authoritative changes and regenerate the manifest deliberately; never update it merely to suppress a checksum failure.
