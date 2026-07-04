# Issue 812 Task 1: First-release Vocabulary Scope

This document defines the first-release vocabulary scope for the Subject Metadata Enrichment Assistant from Epic 770.

It covers only supported subject schemes, authoritative source registries, cache expectations, and explicit exclusions. It does not implement discovery, matching, acceptance actions, or any follow-up issue such as Issue 813.

## Local Context

ERNIE stores DataCite subjects in `subjects` with these enrichment-relevant fields:

- `value`
- `subject_scheme`
- `scheme_uri`
- `value_uri`
- `classification_code`
- `breadcrumb_path`
- `language`

The first release should enrich existing `Subject` rows only when a controlled-vocabulary source is already supported locally and the assistant can propose missing metadata from deterministic evidence.

The assistant must treat a subject as controlled when `subject_scheme` is present after trimming. Subjects without a scheme are free-text subjects and are handled by the rules in `02-mapping-and-normalization-rules.md`.

## Supported First-release Schemes

The first release supports vocabularies already represented by local fetch commands, parsers, import logic, or `SubjectBreadcrumbPathResolverService`.

| Local scheme | Accepted legacy scheme forms | Canonical scheme URI | Authoritative source | Local cache | Notes |
| --- | --- | --- | --- | --- | --- |
| `Science Keywords` | `NASA/GCMD Earth Science Keywords`, `GCMD Science Keywords`, any scheme containing `Science Keywords` | `https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords` | NASA CMR KMS / GCMD RDF endpoint | `gcmd-science-keywords.json` | Use GCMD concept URIs as `value_uri`. |
| `Platforms` | `NASA/GCMD Earth Platforms Keywords`, `GCMD Platforms`, any scheme containing `Platform` | `https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms` | NASA CMR KMS / GCMD RDF endpoint | `gcmd-platforms.json` | Use GCMD concept URIs as `value_uri`. |
| `Instruments` | `NASA/GCMD Instruments`, `GCMD Instruments`, any scheme containing `Instrument` | `https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments` | NASA CMR KMS / GCMD RDF endpoint | `gcmd-instruments.json` | Use GCMD concept URIs as `value_uri`. |
| `GEMET - GEneral Multilingual Environmental Thesaurus` | Any scheme containing `GEMET` | `http://www.eionet.europa.eu/gemet/concept/` | European Environment Agency GEMET REST API | `gemet-thesaurus.json` | Local hierarchy is SuperGroup > Group > Concept. |
| `International Chronostratigraphic Chart` | Any scheme containing `Chronostrat` | `http://resource.geosciml.org/vocabulary/timescale/gts2020` | ARDC Linked Data API for the CSIRO International Chronostratigraphic Chart, GTS 2020 | `chronostrat-timescale.json` | Boundary/GSSP concepts are filtered by the parser and are not eligible. |
| `Analytical Methods for Geochemistry and Cosmochemistry` | Any scheme containing both `Analytical` and `Method` | `https://w3id.org/geochem/1.0/analyticalmethod/method` | ARDC Linked Data API for EarthChem/GEOROC Analytical Methods | `analytical-methods.json` | `notation` may populate `classification_code` when present. The version is administrator-configurable. |
| `European Science Vocabulary (EuroSciVoc)` | Any scheme containing `EuroSciVoc` or `European Science Vocabulary` | `config('euroscivoc.concept_scheme_uri')` | EU Publications Office EuroSciVoc RDF/SKOS-XL download | `euroscivoc.json` | Use English labels from SKOS/SKOS-XL extraction. |
| `EPOS MSL vocabulary` | Any scheme containing `EPOS MSL` or `MSL vocabulary` | `https://epos-msl.uu.nl/voc` | Utrecht University MSL vocabulary JSON used by ERNIE import tooling | `msl-vocabulary.json` | Included as an import/resolver-backed vocabulary, even though it is not managed through `ThesaurusSetting`. |

## Scope Tiers

### Tier 1: Admin-managed thesauri

These schemes are present in `ThesaurusSetting` and have administrator refresh/status workflows:

- GCMD Science Keywords
- GCMD Platforms
- GCMD Instruments
- ICS Chronostratigraphy
- GEMET Thesaurus
- Analytical Methods for Geochemistry
- EuroSciVoc

Discovery may create suggestions for these schemes only when the corresponding local cache exists and can be parsed.

### Tier 2: Resolver-backed specialty vocabulary

`EPOS MSL vocabulary` is supported for first release because ERNIE already imports it, extracts DataCite XML subjects for it, and resolves breadcrumb paths against `msl-vocabulary.json`.

The assistant must record provenance clearly for MSL suggestions because the refresh path is not represented in `ThesaurusSetting`.

## Required Source Conditions

A vocabulary is eligible for discovery only when all conditions are true:

- The scheme normalizes to one of the supported first-release schemes.
- The authoritative local cache file exists.
- The cache can be decoded as the ERNIE vocabulary tree format.
- Candidate concepts contain at least a stable identifier or notation and an English or explicit source language.
- The assistant can record the source file, retrieval or cache timestamp when available, scheme, scheme URI, and matching strategy.

If any source condition fails, suppress the suggestion instead of querying remote registries during discovery.

## Explicit Exclusions

The first release does not support:

- Arbitrary DataCite `subjectScheme` values that are not listed above.
- Name-only matching across every available thesaurus.
- Live remote lookups during subject discovery.
- Full thesaurus maintenance, registry synchronization, or new vocabulary import commands.
- Multilingual label expansion beyond source-provided language metadata and the local `en` default.
- Unlisted classification systems such as DDC, ANZSRC/FOR, MeSH, Wikidata, SPDX, ROR, ORCID, or organization/funder registries.
- Suggestions for rows whose current controlled metadata is already complete and consistent.
- Free-text subject conversion unless the strategy in `02-mapping-and-normalization-rules.md` finds one unique high-confidence controlled match. Globally unique exact label or source-synonym matches are allowed, but must carry a curator-facing warning before later acceptance can convert the free keyword into a controlled thesaurus keyword.
- Any implementation, UI, background job, acceptance workflow, or follow-up scope assigned to later sub-issues.

## First-release Outcome

For Issue 812, the expected deliverable is documentation only:

- supported schemes and source registries are defined here,
- mapping and normalization rules are defined in `02-mapping-and-normalization-rules.md`,
- the suggestion payload contract is defined in `03-suggestion-payload-contract.md`.
