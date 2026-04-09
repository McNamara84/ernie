# Implementation Plan: Import IGSNs from DataCite

**Date:** 2026-04-09 (Updated: 2026-04-10)  
**Branch:** `feature/import-igsns`  
**Estimated IGSNs:** ~38,525 (DataCite prefix `10.60510`)

---

## Table of Contents

1. [Context & Problem Analysis](#1-context--problem-analysis)
2. [Metadata Gap Analysis](#2-metadata-gap-analysis)
3. [Phase 0: Investigation Scripts](#3-phase-0-investigation-scripts)
4. [Phase 1: Backend – DataCite Import](#4-phase-1-backend--datacite-import)
5. [Phase 2: Backend – Metadata Enrichment](#5-phase-2-backend--metadata-enrichment)
6. [Phase 3: Frontend – Import UI](#6-phase-3-frontend--import-ui)
7. [Phase 4: Testing](#7-phase-4-testing)
8. [Phase 5: Documentation & Changelog](#8-phase-5-documentation--changelog)
9. [File Overview](#9-file-overview)
10. [Risk Assessment](#10-risk-assessment)

---

## 1. Context & Problem Analysis

### Current State
- The `/resources` page has an "Import from DataCite" button that imports all DOIs from DataCite's production API
- The `/igsns` page currently only supports CSV upload for IGSN creation
- ~38,500 IGSNs are registered at DataCite under prefix `10.60510` (client: `gfz.igsn`)
- Old IGSN landing pages exist at `https://dataservices.gfz-potsdam.de/igsn/igsngfz/index.php?igsn={IGSN}`

### Problem
The DataCite API only provides **standard DataCite metadata** (titles, creators, descriptions, dates, geoLocations, etc.). It does **not** provide IGSN-specific metadata like:
- Sample type, material, depth, collection method
- Platform/cruise information
- Archive/repository information
- Rock classifications, geological ages, geological units
- Parent-child hierarchies

This IGSN-specific metadata is stored in the **old GFZ IGSN infrastructure** and visible on the old landing pages.

### Solution
A two-phase import:
1. **Phase 1:** Import standard metadata from DataCite API (analogous to existing DOI import)
2. **Phase 2:** Enrich with IGSN-specific metadata from the best available legacy source

---

## 2. Metadata Gap Analysis

### What DataCite API provides (standard metadata)

| Field | DataCite JSON Path | Maps to |
|-------|-------------------|---------|
| DOI/IGSN | `attributes.doi` | `Resource.doi` |
| Title | `attributes.titles[].title` | `Title` |
| Creators | `attributes.creators[]` | `ResourceCreator`, `Person`, `Affiliation` |
| Contributors | `attributes.contributors[]` | `ResourceContributor` |
| Publication Year | `attributes.publicationYear` | `Resource.publication_year` |
| Publisher | `attributes.publisher` | `Resource.publisher_id` |
| Resource Type | `attributes.types.resourceTypeGeneral` | `Resource.resource_type_id` → "physical-object" |
| Descriptions | `attributes.descriptions[]` | `Description` |
| Subjects | `attributes.subjects[]` | `Subject` |
| Dates | `attributes.dates[]` | `ResourceDate` |
| GeoLocations | `attributes.geoLocations[]` | `GeoLocation` |
| Related Identifiers | `attributes.relatedIdentifiers[]` | `RelatedIdentifier` |
| Funding References | `attributes.fundingReferences[]` | `FundingReference` |
| Rights | `attributes.rightsList[]` | `Right` |
| Sizes | `attributes.sizes[]` | `Size` |
| Formats | `attributes.formats[]` | `Format` |
| Language | `attributes.language` | `Resource.language_id` |
| Version | `attributes.version` | `Resource.version` |
| Alternate Identifiers | `attributes.identifiers[]` | `AlternateIdentifier` |

### What DataCite does NOT provide (IGSN-specific, from old landing pages)

| Landing Page Section | Field | Maps to IgsnMetadata |
|---------------------|-------|---------------------|
| **General Identifiers** | Type (Core, Individual Sample, ...) | `sample_type` |
| | Name (local accession number) | `AlternateIdentifier` or `user_code` |
| | Parent IGSN | `parent_resource_id` (hierarchy) |
| | Project | `cruise_field_program` |
| | Campaign | (not currently modeled) |
| | Request by | `operator` |
| | Purpose | `sample_purpose` |
| **Sampling Location** | Coordinate System | `coordinate_system` |
| | Elevation | (store in `depth_min`/`depth_max` context) |
| | Country / Province / County / City | (GeoLocation.place enrichment) |
| **Acquisition** | Material | `material` |
| | Rock Classification | `IgsnClassification` records |
| | Collection Method | `collection_method` |
| | Chief Scientist | Contributor with type "Supervisor" |
| | Start/End Date | `ResourceDate` (with "Collected" type) |
| | Funding Agency | `FundingReference` |
| | Comments | `Description` or `collection_method_description` |
| **Repositories** | Current Repository | `current_archive` |
| | Current Repository Contact | `current_archive_contact` |
| | Original Repository | (not currently modeled, can reuse `current_archive`) |
| | Original Repository Contact | (similar) |

### IGSN-specific relations (also not in DataCite)

| Relation | Source |
|----------|--------|
| `IgsnClassification` (rock types) | Old landing pages → "Rock Classification" |
| `IgsnGeologicalAge` (time periods) | Old landing pages (if available) |
| `IgsnGeologicalUnit` (strat units) | Old landing pages (if available) |

---

## 3. Phase 0: Investigation Scripts

**Goal:** Determine which legacy data source provides the best access to IGSN-specific metadata. Test in order of preference.

### 3.1 Script: Probe Solr IGSN-Core

**File:** `scripts/probe-solr-igsn.php`

**Purpose:** Test connectivity to the Solr IGSN core on `doidb.wdc-terra.org` and discover the schema/fields.

```
Execution: docker exec ernie-app-dev php scripts/probe-solr-igsn.php
```

**What it does:**
1. Connect to `https://{SOLR_USER}:{SOLR_PASSWORD}@{SOLR_HOST}:{SOLR_PORT}{SOLR_CORE_IGSN}`
2. Attempt `select?q=*:*&rows=1&wt=json` to get a sample document
3. Attempt `admin/luke?show=schema&wt=json` to inspect field definitions
4. Print all fields found in the Solr document
5. Query for a known IGSN (e.g., `GFBNO7002EC8H101`) to verify data

**Success criteria:** Returns structured IGSN data with fields like `sampleType`, `material`, `parentIgsn`, etc.

### 3.2 Script: Probe IGSN-Metadata Database

**File:** `scripts/probe-igsn-db.php`

**Purpose:** Test connectivity to the `igsn-metadata` database on `rz-mysql2.gfz-potsdam.de` and discover tables/columns.

**What it does:**
1. Add temporary `igsn_legacy` connection to Laravel config (or use raw PDO)
2. Connect using `DB_IGSN_HOST`, `DB_IGSN_NAME`, `DB_IGSN_USER`, `DB_IGSN_PASSWORD` from `stack.env`
3. Run `SHOW TABLES` to list all tables
4. Run `DESCRIBE <table>` for each table
5. Query a sample record for known IGSN `GFBNO7002EC8H101`
6. Print table structures and sample data

**Success criteria:** Returns table schema with identifiable IGSN fields.

### 3.3 Script: Probe Landing Page Crawling

**File:** `scripts/probe-igsn-landing-page.php`

**Purpose:** Test if the old landing page HTML can be reliably parsed.

**What it does:**
1. Fetch `https://dataservices.gfz-potsdam.de/igsn/igsngfz/index.php?igsn=GFBNO7002EC8H101`
2. Parse the HTML using Symfony DomCrawler (already in composer.lock)
3. Extract all metadata fields from the HTML tables
4. Print the extracted data in a structured format
5. Test with 3-5 different IGSNs to verify consistency

**Success criteria:** Reliably extracts all metadata fields from the landing page HTML.

### 3.4 Decision Matrix

After running the scripts, choose the enrichment strategy:

| Priority | Source | Pros | Cons |
|----------|--------|------|------|
| **1st** | Solr IGSN-Core | Fast, structured, bulk queries | May require VPN, auth issues |
| **2nd** | IGSN-Metadata DB | Direct, complete, relational | VPN-only, schema unknown |
| **3rd** | Landing Page Crawling | No DB access needed, public | Slow (~38k HTTP requests), HTML fragile |

---

## 3.5 Phase 0 Investigation Results (2026-04-10)

### Data Source Test Results

All probe scripts were executed inside the Docker container (`ernie-app-dev`).

#### 1. Solr (`doidb.wdc-terra.org`) ✅ WORKING

Credentials obtained. Two relevant Solr cores found:

| Core | Total Docs | Purpose |
|------|-----------|---------|
| `igsnaa` | **35,638** | GFZ-registered IGSNs with rich metadata (DIF XML) |
| `igsn` | 10,763,441 | Global IGSN registry (minimal metadata, no DIF XML) |

**Core `igsnaa` document structure** (26 fields):

| Solr Field | Type | Content |
|------------|------|---------|
| `igsn` | string | IGSN handle (e.g. `GFBNO7002EC8H101`) |
| `doi` | string | Old handle DOI (`10273/GFBNO7002EC8H101`) |
| `dif` | base64 | **Rich IGSN metadata XML** (see below) |
| `xml` | base64 | IGSN kernel XML (minimal, registration data only) |
| `sampletype` | array | Sample type(s) (e.g. `["Core"]`) |
| `material` | array | Material(s) (e.g. `["Rock"]`) |
| `creator` | array | Creator names |
| `description` | array | Description text |
| `relatedIdentifier` | array | Related identifiers (parent IGSN) |
| `datacentre_symbol` | string | Registering datacentre |
| `prefix` | string | Handle prefix (e.g. `10273/GF`) |
| `has_dif` | boolean | Whether DIF XML is available |
| `has_metadata` | boolean | Whether kernel XML is available |
| `created/updated/minted` | datetime | Timestamps |

**DIF XML** (`dif` field, base64-decoded) contains ALL IGSN-specific fields:

```xml
<supplementalMetadata>
  <record>
    <sample xmlns="http://pmd.gfz-potsdam.de/igsn/schemas/description-ext/1.3">
      <user_code>GEOFERN Expedition 7002</user_code>
      <sample_type>Core</sample_type>
      <name>7002_1_A_036</name>
      <igsn>GFBNO7002EC8H101</igsn>
      <parent_igsn>GFBNO7002EHG0001</parent_igsn>
      <latitude>52.427095104</latitude>
      <longitude>13.5286198757</longitude>
      <coordinate_system>WGS84</coordinate_system>
      <elevation>35.07</elevation>
      <material>Rock</material>
      <depth_min>310.8</depth_min>
      <collection_method>Coring</collection_method>
      <collection_method_descr>Section Count: 3; ...</collection_method_descr>
      <platform_type>R</platform_type>
      <collector>Norden, Ben</collector>
      <current_archive>BGR</current_archive>
      <current_archive_contact>Tina.Kollaske@bgr.de</current_archive_contact>
      <!-- ... more fields ... -->
    </sample>
  </record>
</supplementalMetadata>
```

**DIF XML coverage per datacentre:**

| Datacentre | Docs | DIF XML | IGSN Fields (of 15 tested) |
|------------|------|---------|---------------------------|
| IGSNDB.ICDP | 15,813 | ✅ | **15/15 (100%)** |
| IGSNDB.HEREON | 12,041 | ✅ | 9/15 |
| IGSNDB.ESP | 3,398 | ✅ | 11/15 |
| IGSNDB.AWIENV | 2,056 | ✅ | 9/15 |
| IGSNDB.MEDUSA | 844 | ✅ | 12/15 |
| IGSNDB.SO273 | 432 | ✅ | 9/15 |
| IGSNDB.GFZ | 357 | ❌ | 0 |
| IGSNDB.HLL | 656 | ❌ | 0 |
| IGSNDB.GES | 41 | ✅ | 9/15 |

**Overall DIF coverage:** 35,429 / 35,638 (99.4%). Only GFZ (357) and HLL (656) lack DIF XML.

**Handle mapping:** Solr uses old handle prefix `10273/`, DataCite uses DOI prefix `10.60510/`. The IGSN suffix is identical (case-insensitive).

**Gap:** DataCite has 38,525 IGSNs, Solr `igsnaa` has 35,638 → ~2,887 IGSNs exist only in DataCite (newer registrations post-migration).

**Status:** ✅ Working. Primary enrichment source for 35,429 IGSNs (92% of total).

#### 2. IGSN-Metadata Database (`rz-mysql2.gfz-potsdam.de/igsn-metadata`)

| Attempt | Result |
|---------|--------|
| SSL connection | `MySQL server has gone away` (Error 2006) |
| Non-SSL connection | `Access denied for user` (Error 1045) |

**Status:** 🔒 Blocked (initial test with placeholder credentials). Real credentials obtained — see Section 3.6 for updated results.

#### 3. Landing Page AJAX Endpoints (`dataservices.gfz-potsdam.de/igsn/igsngfz/`)

**Critical discovery:** The landing pages are **SPA architecture** using jQuery + FancyTree. The initial HTML is a 19,913-byte shell with all fields set to "N/A". Data is loaded via AJAX:

| AJAX Endpoint | Purpose | Format |
|---------------|---------|--------|
| `infotext.php?igsn={IGSN}` | Sample metadata (all fields) | HTML (XSLT-transformed) |
| `treeinfo.php?igsn={IGSN}` | Parent-child hierarchy | JSON |
| `children.php?igsn={IGSN}&mode=children` | Child samples list | JSON |

**Key finding:** `infotext.php` **requires `Referer` and `X-Requested-With` headers** to return actual data. Without these headers, it returns a template with all N/A values (2,432 bytes). With headers, it returns populated data (2,815+ bytes).

Required headers:
```
Referer: https://dataservices.gfz-potsdam.de/igsn/igsngfz/index.php?igsn={IGSN}
X-Requested-With: XMLHttpRequest
```

**Status:** ⚠️ Partially working. See coverage analysis below.

#### 4. DataCite API (`api.datacite.org`)

| Test | Result |
|------|--------|
| Total IGSNs (prefix 10.60510) | **38,525** |
| Sample DOI (10.60510/gfbno7002ec8h101) | **✅ Full standard metadata** |
| Fields: titles, creators, dates, relatedIdentifiers | ✅ Available |
| Fields: geoLocations, descriptions | ❌ Empty for tested IGSNs |
| IGSN-specific fields (material, sample_type, etc.) | ❌ Not in DataCite |

**Status:** ✅ Fully accessible. Provides standard DataCite metadata for all 38,525 IGSNs.

### IGSN Handle Prefix Distribution

The 38,525 IGSNs use different handle prefixes. The landing page enrichment only works for certain prefixes:

| Prefix | Count | % of Total | Landing Page Data |
|--------|-------|------------|-------------------|
| ICDP* | 14,008 | 36.4% | ❌ No data |
| GFHER* | 12,041 | 31.3% | ❌ No data |
| GF* (bare, e.g. GF1211S) | ~5,800 | ~15.1% | ❌ No data |
| AWFWI* | 3,804 | 9.9% | ❌ No data |
| SSDPRR* | 1,783 | 4.6% | ❌ No data |
| GFLVK* | 546 | 1.4% | ❌ No data |
| **GFBNO*** | **222** | **0.6%** | **✅ Rich data** |
| GFNAS* | 120 | 0.3% | ❌ No data |
| GFHWO* | 83 | 0.2% | ❌ No data |
| GFJUB* | 40 | 0.1% | ❌ No data |

**Critical finding:** Only **222 out of 38,525 IGSNs (0.6%)** have enrichment data available via the landing page AJAX endpoint. All other prefixes return empty N/A templates.

### Landing Page Field Mapping (for GFBNO* IGSNs)

For the 222 GFBNO* IGSNs, `infotext.php` provides these fields:

| Landing Page Field | IgsnMetadata Column | Coverage |
|---|---|---|
| **General Identifiers** | | |
| Project | `cruise_field_program` | ✅ 2/2 tested |
| Campaign | _(not modeled)_ | ❌ Always N/A |
| Type (Core, Individual Sample) | `sample_type` | ✅ 2/2 tested |
| Name (local accession number) | `user_code` | ✅ 2/2 tested |
| Parent IGSN | `parent_resource_id` | ✅ 2/2 tested |
| Request by | `operator` | ✅ 2/2 tested |
| Purpose | `sample_purpose` | ✅ 1/2 tested |
| **Sampling Location** | | |
| Latitude | → `GeoLocation` | ✅ 1/2 (parent only) |
| Longitude | → `GeoLocation` | ✅ 1/2 (parent only) |
| Coordinate System | `coordinate_system` | ✅ 1/2 |
| Elevation | → `GeoLocation` | ✅ 1/2 |
| Country | → `GeoLocation.place` | ✅ 2/2 tested |
| City | → `GeoLocation.place` | ✅ 2/2 tested |
| **Acquisition** | | |
| Material | `material` | ✅ 2/2 tested |
| Rock Classification | → `IgsnClassification` | ❌ Always N/A |
| Collection Method | `collection_method` | ✅ 1/2 |
| Collection Method (detail) | `collection_method_description` | ✅ 1/2 |
| Chief Scientist | → `ResourceContributor` | ✅ 2/2 tested |
| Start Date | → `ResourceDate` (Collected) | ✅ 2/2 tested |
| End Date | → `ResourceDate` (Collected) | ✅ 2/2 tested |
| **Repositories** | | |
| Current Repository | `current_archive` | ✅ 2/2 tested |
| Current Repository Contact | `current_archive_contact` | ✅ 2/2 tested |
| Original Repository | _(store in `description_json`)_ | ✅ 2/2 tested |
| Original Repository Contact | _(store in `description_json`)_ | ✅ 2/2 tested |

### 3.6 IGSN Legacy Database Investigation

**Connection:** `rz-mysql2.gfz-potsdam.de:3306` / Database `igsn-metadata` / User `igsn-metadata_rw`

**Schema Overview (13 tables):**

| Table | Rows | Purpose |
|-------|------|---------|
| `dataset` | 35,640 | IGSN registry – `doi` column has handle (e.g. `10273/GFBNO7002EC8H101`) |
| `metadata` | 232,196 | Multiple versions per dataset – `dif` (blob) + `xml` (mediumblob) |
| `datacentre` | 10 | Data centres (GFZ, ICDP, HEREON, etc.) |
| `prefix` | 18 | Handle prefixes |
| `allocator` | 3 | Top-level allocators |
| `mesi*` (7 tables) | Various | MESI vocabulary tables |

**Key relationships:** `metadata.dataset` → `dataset.id` (FK), `dataset.datacentre` → `datacentre.id` (FK), `dataset.prefix` → `prefix.id` (FK).

**Handle mapping:** DB uses old-style prefix `10273/` while DataCite uses `10.60510/`. The IGSN suffix is identical and case-insensitive, so matching is via: `UPPER(SUBSTRING_INDEX(dataset.doi, '/', -1))`.

**DIF XML coverage per datacentre:**

| Datacentre | Datasets | With DIF | Coverage | vs Solr |
|------------|----------|----------|----------|---------|
| ICDP | 15,813 | 15,781 | 99.8% | Same |
| HEREON | 12,041 | 12,041 | 100% | Same |
| ESP | 3,398 | 3,373 | 99.3% | Same |
| AWIENV | 2,056 | 2,055 | 100% | Same |
| MEDUSA | 844 | 844 | 100% | Same |
| **HLL** | **656** | **654** | **99.7%** | **Solr: 0 DIF** → DB adds 654! |
| SO273 | 432 | 432 | 100% | Same |
| **GFZ** | **357** | **222** | **62.2%** | **Solr: 0 DIF** → DB adds 222! |
| GES | 41 | 41 | 100% | Same |
| CRC1211 | 2 | 0 | 0% | Same |
| **Total** | **35,640** | **35,443** | **99.4%** | |

**Key findings:**
- DB has **35,640 active datasets** (vs Solr 35,638 – nearly identical)
- **35,443 unique datasets have DIF XML** (99.4%)
- **HLL:** 654/656 have DIF in DB but **0 in Solr** → DB provides 654 additional enrichable IGSNs
- **GFZ:** 222/357 have DIF in DB but **0 in Solr** → DB provides 222 additional enrichable IGSNs
- Latest DB update: **2024-01-08** – only 2 records created after 2023-01-01
- Average **6.5 metadata versions** per dataset (use latest version for import)
- DIF XML format is **identical** to Solr DIF – same parser can be reused

**Gap to DataCite:** 38,525 − 35,640 = **2,885 IGSNs** exist only in DataCite (post-migration, registered after DB/Solr were frozen). These will have standard DataCite metadata only – no IGSN-specific enrichment available from any source.

### Investigation Conclusions (final)

| Source | Status | Coverage | Recommendation |
|--------|--------|----------|----------------|
| **DataCite API** | ✅ Works | 38,525 (100%) | **Primary import source** (standard metadata) |
| **Solr `igsnaa`** | ✅ Works | 35,429 DIF (92%) | **Primary enrichment source** (DIF XML) |
| **IGSN-Metadata DB** | ✅ Works | 35,443 DIF (92%) | **Fallback enrichment** – adds 876 IGSNs (HLL+GFZ) |
| **Landing Page AJAX** | ⚠️ Partial | 222 (0.6%) | Superseded by Solr + DB |

**Combined enrichment coverage:** Solr provides 35,429 + DB fills gap for HLL (654) + GFZ (222) = **36,305 IGSNs enrichable** (94.2% of 38,525).

Remaining **2,220 unenrichable IGSNs:** 2,885 DataCite-only + 135 GFZ without DIF + 2 HLL without DIF + 2 CRC1211 without DIF − overlap ≈ **2,220**. These get standard DataCite metadata only.

### Recommended Strategy (final)

**Phase 1:** Import all 38,525 IGSNs from DataCite API with standard metadata. Create `IgsnMetadata` records with `upload_status='registered'`.

**Phase 2a (Solr enrichment – primary):** Enrich from Solr `igsnaa` DIF XML. Covers 35,429 IGSNs (~92%). Provides sample_type, material, coordinates, depth, collection_method, archive, parent_igsn, etc.

**Phase 2b (DB enrichment – fallback):** For IGSNs not enriched by Solr, query `igsn-metadata` DB for DIF XML. Adds ~876 IGSNs (HLL=654, GFZ=222). Same DIF parser, just different data source.

**Phase 2 total:** ~36,305 IGSNs enriched (94.2%), ~2,220 with standard metadata only.

**Decision needed from user:** Proceed with implementation (Phase 1 + Phase 2a + 2b)?

---

## 4. Phase 1: Backend – DataCite Import

### 4.1 Configuration

**File:** `config/datacite.php`

Add IGSN-specific prefix to the production config:

```php
'production' => [
    // ... existing config ...
    'igsn_prefix' => '10.60510',
],
'test' => [
    // ... existing config ...
    'igsn_prefix' => env('DATACITE_TEST_IGSN_PREFIX', '10.60510'),
],
```

### 4.2 Controller: `IgsnImportController`

**File:** `app/Http/Controllers/IgsnImportController.php`

Follows the same pattern as `DataCiteImportController`:

| Method | Route | Purpose |
|--------|-------|---------|
| `start(Request)` | `POST /igsns/import/start` | Dispatch `ImportIgsnsFromDataCiteJob`, return UUID |
| `status(Request, string)` | `GET /igsns/import/{importId}/status` | Return cached progress |
| `cancel(Request, string)` | `POST /igsns/import/{importId}/cancel` | Mark as cancelled in cache |

**Authorization:** Reuse `importFromDataCite` policy on `Resource::class` (same permission level).

**Cache key prefix:** `igsn_import:{importId}` (distinct from `datacite_import:{importId}`).

### 4.3 Routes

**File:** `routes/web.php`

```php
Route::post('igsns/import/start', [IgsnImportController::class, 'start'])
    ->name('igsns.import.start')
    ->middleware('auth');

Route::get('igsns/import/{importId}/status', [IgsnImportController::class, 'status'])
    ->name('igsns.import.status')
    ->middleware('auth');

Route::post('igsns/import/{importId}/cancel', [IgsnImportController::class, 'cancel'])
    ->name('igsns.import.cancel')
    ->middleware('auth');
```

### 4.4 Service: `IgsnImportService`

**File:** `app/Services/IgsnImportService.php`

Similar to `DataCiteImportService` but scoped to `10.60510` prefix only:

| Method | Purpose |
|--------|---------|
| `getTotalIgsnCount(): int` | Query DataCite API for total count with prefix `10.60510` |
| `fetchAllIgsns(): Generator` | Yield all IGSN records using cursor-based pagination |
| `fetchIgsnsPage(string $cursor): array` | Fetch one page (up to 1000 records) |

**Key differences from `DataCiteImportService`:**
- Single prefix only (`10.60510`)
- Filter for `resource-type-id=physical-object` in API query
- Same rate limiting (200ms between pages)
- Same retry logic for transient failures

### 4.5 Transformer: `DataCiteToIgsnTransformer`

**File:** `app/Services/DataCiteToIgsnTransformer.php`

Extends the standard `DataCiteToResourceTransformer` logic with IGSN-specific handling:

| Method | Purpose |
|--------|---------|
| `transform(array $doiData, int $userId): Resource` | Create Resource + IgsnMetadata from DataCite data |

**Process:**
1. Call similar logic as `DataCiteToResourceTransformer` for standard fields
2. Ensure `resource_type_id` is set to "physical-object"
3. Create `IgsnMetadata` record with:
   - `upload_status = 'registered'` (already registered at DataCite)
   - All IGSN-specific fields initially `null` (to be enriched in Phase 2)
4. Extract IGSN handle from `url` field (e.g., `https://dataservices.gfz.de/igsn/igsngfz/index.php?igsn=GFBNO7002EC8H101` → `GFBNO7002EC8H101`)

### 4.6 Job: `ImportIgsnsFromDataCiteJob`

**File:** `app/Jobs/ImportIgsnsFromDataCiteJob.php`

Follows the same pattern as `ImportFromDataCiteJob`:

| Aspect | Detail |
|--------|--------|
| Queue | `default` |
| Timeout | 14400s (4 hours, longer due to enrichment) |
| Tries | 1 |
| Progress update interval | Every 50 IGSNs |
| Cancellation check interval | Every 50 IGSNs |
| Duplicate detection | Check `Resource.doi` for existing IGSN |

**Process per IGSN:**
1. Check if IGSN already exists in ERNIE → skip if yes
2. Transform DataCite data to Resource + IgsnMetadata via `DataCiteToIgsnTransformer`
3. (Phase 2) Enrich with IGSN-specific metadata from legacy source
4. Update progress counters

**Progress cache structure** (same as DOI import):
```php
[
    'status' => 'pending|running|completed|failed|cancelled',
    'total' => 38500,
    'processed' => 0,
    'imported' => 0,
    'skipped' => 0,
    'failed' => 0,
    'skipped_dois' => [],     // Already existing IGSNs
    'failed_dois' => [],      // Failed imports with error details
    'started_at' => '...',
    'completed_at' => null,
    'error' => null,
]
```

---

## 5. Phase 2: Backend – Metadata Enrichment

This phase depends on the results of the investigation scripts (Phase 0). Three strategies are prepared:

### 5.1 Strategy A: Solr Enrichment (Preferred)

**File:** `app/Services/IgsnSolrEnrichmentService.php`

| Method | Purpose |
|--------|---------|
| `enrichFromSolr(Resource $resource, string $igsnHandle): bool` | Fetch & apply IGSN metadata from Solr |
| `querySolr(string $igsnHandle): ?array` | HTTP GET to Solr select endpoint |
| `mapSolrToIgsnMetadata(array $solrDoc, IgsnMetadata $meta): void` | Map Solr fields to model |

**Connection:**
```
https://{SOLR_USER}:{SOLR_PASSWORD}@{SOLR_HOST}:{SOLR_PORT}{SOLR_CORE_IGSN}select
    ?q=sampleNumber:{igsnHandle}
    &wt=json
    &rows=1
```

**Rate limiting:** 100ms between requests (10 req/sec max).

### 5.2 Strategy B: Database Enrichment

**File:** `app/Services/IgsnLegacyDbEnrichmentService.php`

**New Laravel DB connection in `config/database.php`:**
```php
'igsn_legacy' => [
    'driver' => 'mysql',
    'host' => env('DB_IGSN_HOST', '127.0.0.1'),
    'port' => env('DB_IGSN_PORT', '3306'),
    'database' => env('DB_IGSN_NAME', 'igsn-metadata'),
    'username' => env('DB_IGSN_USER', 'root'),
    'password' => env('DB_IGSN_PASSWORD', ''),
    // ... SSL config similar to metaworks connection
],
```

| Method | Purpose |
|--------|---------|
| `enrichFromDb(Resource $resource, string $igsnHandle): bool` | Query legacy DB for IGSN metadata |
| `mapDbRowToIgsnMetadata(object $row, IgsnMetadata $meta): void` | Map DB columns to model |
| `resolveParentIgsn(string $parentHandle): ?int` | Resolve parent IGSN to resource_id |

### 5.3 Strategy C: Landing Page Crawling (Fallback)

**File:** `app/Services/IgsnLandingPageCrawlerService.php`

Uses Symfony DomCrawler (already available in dependencies).

| Method | Purpose |
|--------|---------|
| `enrichFromLandingPage(Resource $resource, string $igsnHandle): bool` | Fetch & parse old landing page |
| `fetchLandingPage(string $igsnHandle): ?string` | HTTP GET with error handling |
| `parseLandingPage(string $html): array` | Extract metadata from HTML tables |
| `mapCrawledToIgsnMetadata(array $data, IgsnMetadata $meta): void` | Map parsed data to model |

**Landing Page URL pattern:**
```
https://dataservices.gfz-potsdam.de/igsn/igsngfz/index.php?igsn={igsnHandle}
```

**HTML parsing targets** (based on analysis of actual landing pages):

| HTML Section | Table Rows to Extract |
|---|---|
| "General Identifiers" | Project, Campaign, Type, Name, Parent IGSN, Request by, Purpose |
| "Sampling Location" | Latitude, Longitude, Coordinate System, Elevation, Country, City |
| "Acquisition" | Material, Rock Classification, Collection Method, Chief Scientist, Start/End Date |
| "Repositories" | Current Repository, Current Repository Contact |

**Rate limiting:** 500ms between requests (~2 req/sec to avoid overloading old server).

**Circuit breaker:** If 10 consecutive requests fail, disable crawling for remaining IGSNs.

### 5.4 Enrichment Orchestrator

**File:** `app/Services/IgsnEnrichmentService.php`

Wraps the three strategies with fallback chain:

```php
class IgsnEnrichmentService
{
    public function enrich(Resource $resource, string $igsnHandle): bool
    {
        // Try strategies in order, stop on first success
        // Each strategy is non-critical: failure logs warning, returns false
    }
}
```

**Circuit breaker pattern** (same as MetaworksDownloadUrlService in the existing DOI import):
- If the primary source fails, disable it for remaining IGSNs
- Log warning, continue import without enrichment
- Enrichment is non-critical: import succeeds with DataCite data even without it

### 5.5 Parent-Child Hierarchy Resolution

After all IGSNs are imported, resolve parent-child relationships:

1. During import: store `parent_igsn` handle as temporary reference
2. After all imports: second pass to resolve `parent_resource_id` by looking up parent IGSN DOI
3. Handle cases where parent IGSN may not exist (log warning, leave null)

This is done as a post-processing step because parent IGSNs may be imported after their children.

---

## 6. Phase 3: Frontend – Import UI

### 6.1 Import Modal Component

**File:** `resources/js/components/igsns/modals/ImportIgsnsModal.tsx`

Adapts `ImportFromDataCiteModal.tsx` with IGSN-specific adjustments:

**Differences from DOI import modal:**
- Different icon (use IGSN-related icon or `Globe` icon)
- Description text: "Import all registered IGSNs from DataCite..."
- Bullet points explain IGSN-specific behavior
- API endpoints point to `/igsns/import/...` instead of `/datacite/import/...`
- Labels: "IGSNs" instead of "DOIs", "Imported" / "Skipped" / "Failed"
- Success message: "Successfully imported {count} IGSNs..."

**Modal states** (same as DOI import):
- `confirm` → User confirms import start
- `running` → Progress bar with live counters
- `completed` → Summary with collapsible skipped/failed lists
- `failed` → Error message

### 6.2 IGSN Index Page Update

**File:** `resources/js/pages/igsns/index.tsx`

Add "Import IGSNs" button in the page header toolbar (next to existing buttons):

```tsx
<Button onClick={() => setIsImportModalOpen(true)}>
    <Download className="mr-2 size-4" />
    Import IGSNs
</Button>
```

**New state:**
```tsx
const [isImportModalOpen, setIsImportModalOpen] = useState(false);
```

**New modal instance:**
```tsx
<ImportIgsnsModal
    isOpen={isImportModalOpen}
    onClose={() => setIsImportModalOpen(false)}
    onSuccess={() => router.reload()}
/>
```

**Visibility:** Show button only when `importFromDataCite` permission is available (add to page props if not already present).

### 6.3 Wayfinder Route Generation

After adding the new routes, regenerate Wayfinder routes:

```bash
docker exec ernie-app-dev php artisan wayfinder:generate
```

This creates type-safe route functions in `resources/js/routes/igsns/`.

---

## 7. Phase 4: Testing

### 7.1 Unit Tests (Pest)

| Test File | Tests |
|-----------|-------|
| `tests/pest/Unit/IgsnImportServiceTest.php` | API URL construction, pagination, prefix filtering |
| `tests/pest/Unit/DataCiteToIgsnTransformerTest.php` | Field mapping, IgsnMetadata creation, resource_type assertion |
| `tests/pest/Unit/IgsnLandingPageCrawlerServiceTest.php` | HTML parsing with fixture files |

### 7.2 Feature Tests (Pest)

| Test File | Tests |
|-----------|-------|
| `tests/pest/Feature/IgsnImportControllerTest.php` | Auth, start/status/cancel endpoints, policy checks |
| `tests/pest/Feature/ImportIgsnsFromDataCiteJobTest.php` | Job dispatch, progress updates, duplicate handling, cancellation |

### 7.3 Frontend Tests (Vitest)

| Test File | Tests |
|-----------|-------|
| `tests/vitest/components/igsns/ImportIgsnsModal.test.tsx` | Modal states, progress display, API calls |

### 7.4 PHPStan Validation

Run after all PHP changes:
```bash
docker exec ernie-app-dev ./vendor/bin/phpstan
```

---

## 8. Phase 5: Documentation & Changelog

### 8.1 Changelog Entry

**File:** `resources/data/changelog.json`

```json
{
    "title": "Import IGSNs from DataCite",
    "description": "Added ability to import all registered IGSNs from the DataCite production API, with automatic enrichment of sample-specific metadata from the legacy GFZ IGSN infrastructure."
}
```

### 8.2 User Documentation

**File:** `resources/js/pages/docs.tsx`

Add documentation section for the IGSN import feature:
- How to trigger the import
- What data is imported
- How duplicates are handled
- Expected duration for full import

### 8.3 OpenAPI Documentation

**File:** `resources/data/openapi.json`

Add three new endpoints:
- `POST /igsns/import/start`
- `GET /igsns/import/{importId}/status`
- `POST /igsns/import/{importId}/cancel`

---

## 9. File Overview

### New Files

| File | Purpose |
|------|---------|
| `scripts/probe-solr-igsn.php` | Investigation: Solr connectivity |
| `scripts/probe-igsn-db.php` | Investigation: Legacy DB connectivity |
| `scripts/probe-igsn-landing-page.php` | Investigation: Landing page crawling |
| `app/Http/Controllers/IgsnImportController.php` | Import endpoints (start/status/cancel) |
| `app/Services/IgsnImportService.php` | DataCite API queries for IGSN prefix |
| `app/Services/DataCiteToIgsnTransformer.php` | DataCite → Resource + IgsnMetadata |
| `app/Services/IgsnEnrichmentService.php` | Enrichment orchestrator (fallback chain) |
| `app/Services/IgsnSolrEnrichmentService.php` | Strategy A: Solr enrichment |
| `app/Services/IgsnLegacyDbEnrichmentService.php` | Strategy B: Direct DB enrichment |
| `app/Services/IgsnLandingPageCrawlerService.php` | Strategy C: HTML crawling fallback |
| `app/Jobs/ImportIgsnsFromDataCiteJob.php` | Queue job for background import |
| `resources/js/components/igsns/modals/ImportIgsnsModal.tsx` | Frontend import modal |
| `tests/pest/Unit/IgsnImportServiceTest.php` | Unit tests |
| `tests/pest/Unit/DataCiteToIgsnTransformerTest.php` | Unit tests |
| `tests/pest/Feature/IgsnImportControllerTest.php` | Feature tests |
| `tests/pest/Feature/ImportIgsnsFromDataCiteJobTest.php` | Feature tests |
| `tests/vitest/components/igsns/ImportIgsnsModal.test.tsx` | Frontend tests |

### Modified Files

| File | Changes |
|------|---------|
| `config/datacite.php` | Add `igsn_prefix` setting |
| `config/database.php` | Add `igsn_legacy` connection (if Strategy B chosen) |
| `routes/web.php` | Add 3 import routes |
| `resources/js/pages/igsns/index.tsx` | Add import button + modal |
| `app/Http/Controllers/IgsnController.php` | Add `importFromDataCite` permission to page props |
| `resources/data/changelog.json` | Add changelog entry |
| `resources/js/pages/docs.tsx` | Add documentation section |
| `resources/data/openapi.json` | Add API documentation |

---

## 10. Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Solr/DB behind VPN, not reachable from Docker dev | Cannot enrich locally | ⚠️ **Confirmed:** Both Solr (401) and DB (Access denied) need real credentials. Import works without enrichment. |
| 38,500 HTTP requests for landing page crawling | ~5+ hours import time | ✅ **Moot:** Only 222 GFBNO* IGSNs have LP data. Crawling 222 takes ~2 minutes. |
| Old landing page HTML structure changes | Crawler breaks | XSLT-based HTML is stable. Requires `Referer` + `X-Requested-With` headers. |
| DataCite API rate limiting (6 req/sec) | Import slows down | 200ms delay between pages (same as DOI import) |
| Memory issues with 38k+ records | Job OOM | Process one-by-one with generator, no batch loading |
| Parent IGSN referenced before import | `parent_resource_id` unresolvable | Two-pass: import all, then resolve parents. DataCite provides `relatedIdentifiers` with `IsPartOf` for parent DOIs. |
| Duplicate import attempts | Redundant data | Check-then-insert in transaction (same as DOI import) |
| CSRF token expiry during long import | Frontend polling fails | Import continues server-side; status endpoint handles missing CSRF gracefully |
| 99.4% of IGSNs lack IGSN-specific metadata | Incomplete IgsnMetadata records | Phase 2b with Solr/DB credentials will fill gaps. `null` fields are valid default. |

---

## 11. Investigation Scripts Reference

Scripts created during Phase 0, located in `scripts/`:

| Script | Purpose | Key Finding |
|--------|---------|-------------|
| `probe-solr-igsn.php` | Solr connectivity test | 401 Unauthorized (no credentials) |
| `probe-igsn-db.php` | Legacy DB connectivity test | Access denied (no password) |
| `probe-igsn-landing-page.php` | Landing page HTML parsing | SPA architecture, empty HTML shell |
| `probe-igsn-html-dump.php` | Raw HTML structure analysis | jQuery + FancyTree, AJAX data loading |
| `probe-igsn-ajax-endpoint.php` | AJAX endpoint direct test | Needs Referer header for data |
| `probe-igsn-deep.php` | Alt. Solr paths + Referer test | **Referer header = data!** |
| `probe-igsn-field-mapping.php` | Field extraction + mapping | 30 fields, good coverage for GFBNO* |
| `probe-igsn-prefix-distribution.php` | Handle prefix distribution | 7+ prefixes, GFBNO = 0.6% |
| `probe-prefix-counts.php` | Exact count per prefix | ICDP=36%, GFHER=31%, GF*=15%... |
| `probe-all-prefixes.php` | Complete prefix inventory | 10 prefix groups identified |
| `probe-more-prefixes.php` | LP test for new prefixes | Only GFBNO has data |

These scripts can be deleted after Phase 1 implementation is complete.

## Implementation Order

1. **Phase 0** – Run investigation scripts to determine enrichment source (blockers resolved first)
2. **Phase 1** – Backend DataCite import (controller, service, transformer, job)
3. **Phase 2** – Enrichment service based on Phase 0 results
4. **Phase 3** – Frontend (modal, button, routes)
5. **Phase 4** – Tests + PHPStan
6. **Phase 5** – Documentation + Changelog
